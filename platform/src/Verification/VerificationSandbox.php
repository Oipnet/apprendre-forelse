<?php

namespace App\Verification;

use App\Entity\VerificationStatus;
use App\Instance\ConsoleCommandLine;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Lance `app:verification:rejouer` pour une demande, dans un processus à part, puis fait place nette.
 *
 * Avec VERIFICATION_SANDBOX_UID (le service « verificateur » de deploy/compose.yaml, qui tourne en root avec quelques
 * capacités seulement), ce processus passe sous cet utilisateur par `setpriv`, sans pouvoir regagner de droits
 * (no_new_privs). Il ne peut alors ni lire ni écrire les demandes et verdicts du dossier partagé, ni toucher au moteur ;
 * il n'écrit que dans son dossier jetable. Après lui, tout processus de cet utilisateur est tué et tout fichier qu'il a
 * laissé dans /tmp supprimé : la demande suivante repart d'un conteneur propre. Le conteneur, lui, n'a ni réseau, ni base,
 * ni secrets.
 *
 * Sans VERIFICATION_SANDBOX_UID (développement), le processus tourne sous l'utilisateur courant : à réserver à du code
 * de confiance, comme `content:check`.
 */
final readonly class VerificationSandbox
{
    public function __construct(
        private ConsoleCommandLine $console,
        #[Autowire(env: 'default::int:VERIFICATION_SANDBOX_UID')]
        private ?int $sandboxUid = null,
        /** Au-delà, la réussite est infirmée : un test qui boucle ne bloque pas la file. */
        #[Autowire(env: 'int:VERIFICATION_TIMEOUT')]
        private int $timeout = 600,
        /**
         * Où reconstituer les projets (vide : le dossier temporaire). Sur disque, pas en mémoire : chaque vérification
         * copie le vendor/ de l'environnement, des centaines de Mo.
         */
        #[Autowire(env: 'VERIFICATION_WORK_DIR')]
        private string $workDir = '',
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /** Pourquoi le bac à sable ne peut pas démarrer ici, ou null. */
    public function unavailable(): ?string
    {
        if (null === $this->sandboxUid) {
            return null;
        }
        if (!\function_exists('posix_geteuid') || 0 !== posix_geteuid()) {
            return 'VERIFICATION_SANDBOX_UID demande de lancer le verificateur en root (user: root dans compose.yaml) : il ne peut pas changer d\'utilisateur sinon.';
        }
        if (0 === $this->sandboxUid) {
            return 'VERIFICATION_SANDBOX_UID ne peut pas valoir 0 (root).';
        }

        return null;
    }

    public function run(VerificationRequest $request): VerificationResult
    {
        $run = $this->workDir().'/verification-'.bin2hex(random_bytes(6));
        $this->filesystem->mkdir([$run.'/tmp', $run.'/cache', $run.'/share']);
        // Le processus rejoué, sous un autre utilisateur, écrit là et nulle part ailleurs (hors /tmp, nettoyé après lui).
        $this->filesystem->chmod([$run, $run.'/tmp', $run.'/cache', $run.'/share'], 0o777);

        $process = new Process(
            [...$this->prefix(), ...$this->console->of('app:verification:rejouer', ['--no-interaction'])],
            $this->console->projectDir,
            // Son propre cache Symfony, vide : rien de ce qu'a laissé une demande précédente ne sert à la suivante.
            ['TMPDIR' => $run.'/tmp', 'APP_CACHE_DIR' => $run.'/cache', 'APP_SHARE_DIR' => $run.'/share', 'HOME' => $run.'/tmp'],
            json_encode($request->toArray(), \JSON_THROW_ON_ERROR),
            $this->timeout,
        );
        try {
            $process->run();
            $result = $this->parse($process->getOutput(), $request);
            if (null === $result) {
                $why = trim($process->getErrorOutput()) ?: 'aucun verdict';

                return new VerificationResult($request->progressId, VerificationStatus::Failed, 'La vérification s\'est interrompue : '.mb_substr($why, -200));
            }

            return $result;
        } catch (ProcessTimedOutException) {
            return new VerificationResult($request->progressId, VerificationStatus::Failed, sprintf('Plus de %d s : les tests ne se sont pas terminés.', $this->timeout));
        } finally {
            $this->cleanUp($run);
        }
    }

    private function workDir(): string
    {
        return '' !== $this->workDir ? rtrim($this->workDir, '/') : sys_get_temp_dir();
    }

    /** @return list<string> */
    private function prefix(): array
    {
        return null === $this->sandboxUid
            ? []
            : ['setpriv', '--reuid='.$this->sandboxUid, '--regid='.$this->sandboxUid, '--clear-groups', '--no-new-privs'];
    }

    /** La dernière ligne JSON de la sortie, si elle porte sur cette demande. */
    private function parse(string $output, VerificationRequest $request): ?VerificationResult
    {
        $lines = array_reverse(array_filter(array_map('trim', explode("\n", $output))));
        $data = json_decode((string) ($lines[0] ?? ''), true);
        $result = \is_array($data) ? VerificationResult::fromArray($data) : null;

        return null !== $result && $result->progressId === $request->progressId ? $result : null;
    }

    private function cleanUp(string $run): void
    {
        if (null !== $this->sandboxUid) {
            // Un processus lancé en arrière-plan par le code rejoué ne survit pas à sa demande.
            foreach (glob('/proc/[0-9]*/status') ?: [] as $status) {
                if (1 === preg_match('/^Uid:\s+\d+\s+(\d+)/m', (string) @file_get_contents($status), $match) && (int) $match[1] === $this->sandboxUid) {
                    @posix_kill((int) basename(\dirname($status)), 9);
                }
            }
            foreach (array_unique([$this->workDir(), sys_get_temp_dir(), '/var/tmp', '/dev/shm']) as $directory) {
                foreach (glob($directory.'/{,.}[!.]*', \GLOB_BRACE) ?: [] as $path) {
                    if (@fileowner($path) === $this->sandboxUid) {
                        $this->filesystem->remove($path);
                    }
                }
            }
        }
        $this->filesystem->remove($run);
    }
}
