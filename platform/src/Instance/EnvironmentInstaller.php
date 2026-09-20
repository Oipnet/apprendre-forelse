<?php

namespace App\Instance;

use App\Content\ContentException;
use App\Content\EnvironmentRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Installe un environnement d'exécution depuis un dépôt Git : clone, vérification, empaquetage.
 *
 * **Ce que cela veut dire, en clair** : un administrateur qui colle une URL fait cloner un dépôt puis
 * lancer Composer sur ce dépôt, donc exécuter du code fourni par ce dépôt, sur ce serveur. C'est le
 * prix de « construire ici », et c'est pourquoi la page est réservée aux administrateurs — lesquels
 * sont, sur une instance auto-hébergée, les exploitants de la machine. N'installez que des dépôts en
 * qui vous avez confiance, comme pour un pack de contenu (voir le README).
 *
 * Les garde-fous, eux, sont là pour les accidents plus que pour un adversaire :
 *  - `https://` seulement : pas de `file://` (lecture du disque), pas de `ssh://` ni de `git@` (les
 *    clés du serveur), pas de `git://` (sans chiffrement) ;
 *  - une liste d'hôtes autorisés, facultative (ENVIRONMENT_SOURCES_ALLOWLIST) ;
 *  - clone superficiel, minuterie, et un plafond de taille avant toute exécution ;
 *  - aucun passage par un shell : les commandes sont des tableaux d'arguments.
 */
final readonly class EnvironmentInstaller
{
    /** Au-delà, on refuse avant d'exécuter quoi que ce soit : un environnement n'est pas une sauvegarde. */
    private const int MAX_MEGABYTES = 512;

    private const int CLONE_TIMEOUT = 300;
    private const int BUILD_TIMEOUT = 1800;

    public function __construct(
        private InstalledEnvironments $installed,
        private EnvironmentRegistry $environments,
        /** Dossiers d'environnements, dans l'ordre de recherche : passés à build-env.sh. */
        #[Autowire(env: 'ENVIRONMENTS_DIR')]
        private string $environmentsPath,
        /** Le script d'empaquetage, livré avec les environnements du moteur. */
        #[Autowire('%kernel.project_dir%/../environments/bin/build-env.sh')]
        private string $buildScript,
        /** Hôtes autorisés, séparés par des virgules. Vide : tous. */
        #[Autowire(env: 'ENVIRONMENT_SOURCES_ALLOWLIST')]
        private string $allowlist = '',
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Installe ou réinstalle un environnement. Rend son identifiant.
     *
     * @param string                      $dossier  sous-dossier du dépôt ; vide : la racine
     * @param callable(string): void|null $progress appelé à chaque étape, pour un retour en console
     */
    public function install(string $url, string $ref = '', string $dossier = '', ?callable $progress = null): string
    {
        $say = $progress ?? static fn (string $message) => null;
        $url = $this->checkUrl($url);
        $dossier = $this->checkSubdirectory($dossier);
        if (!$this->installed->isEnabled()) {
            throw new ContentException(sprintf('Aucun dossier d\'environnements installables : %s n\'existe pas ou n\'est pas écrivable (voir INSTALLED_ENVIRONMENTS_DIR).', $this->installed->directory()));
        }

        // Noté avant le clone : tant que le dépôt n'est pas lu, on ignore quel environnement en sortira,
        // et un clone qui échoue doit laisser une trace visible dans l'administration.
        $this->installed->startJob($url, $ref, $dossier);

        $clone = sys_get_temp_dir().'/env-clone-'.bin2hex(random_bytes(6));
        try {
            $say(sprintf('Clonage de %s…', $url));
            $this->clone($url, $ref, $clone);
            $commit = $this->commitOf($clone);

            // Un dépôt peut porter plusieurs environnements : on n'installe que le dossier demandé,
            // mais la taille se mesure sur le clone entier — c'est lui qu'on a rapatrié.
            $source = '' === $dossier ? $clone : $clone.'/'.$dossier;
            if (!is_dir($source)) {
                throw new ContentException(sprintf('Le dépôt ne contient pas de dossier « %s ».', $dossier));
            }

            $id = $this->readId($source);
            $this->checkSize($clone, $id);
            $this->checkAvailable($id);
            $say(sprintf('Environnement « %s » (%s).', $id, substr($commit, 0, 8)));

            // L'état est écrit avant la construction : elle dure des minutes, et l'administration doit
            // pouvoir dire ce qui se passe entre-temps plutôt que d'afficher un dossier muet.
            $destination = $this->installed->directoryOf($id);
            $this->filesystem->remove($destination);
            $this->filesystem->mirror($source, $destination, options: ['override' => true]);
            $this->filesystem->remove($destination.'/.git');
            $this->record($id, $url, $ref, $dossier, $commit, InstalledEnvironment::INSTALLING, 'Empaquetage en cours.');

            $say('Empaquetage (composer install, archive, index de complétion)…');
            $this->build($id);
            $this->record($id, $url, $ref, $dossier, $commit, InstalledEnvironment::READY, 'Installé.');
            $this->installed->finishJob($url, $ref, $dossier);
            $say('Terminé.');

            return $id;
        } catch (\Throwable $e) {
            if (isset($id) && $this->installed->has($id)) {
                $this->record($id, $url, $ref, $dossier, $commit ?? '', InstalledEnvironment::FAILED, $e->getMessage());
            }
            $this->installed->finishJob($url, $ref, $dossier, $e->getMessage());

            throw $e;
        } finally {
            $this->filesystem->remove($clone);
        }
    }

    /** Réinstalle un environnement depuis la source qu'il déclare. */
    public function update(string $id, ?callable $progress = null): string
    {
        $source = $this->installed->read(InstalledEnvironments::id($id))
            ?? throw new ContentException(sprintf('Environnement « %s » : aucune source connue, il n\'a pas été installé depuis un dépôt.', $id));

        return $this->install($source->url, $source->ref, $source->dossier, $progress);
    }

    private function checkUrl(string $url): string
    {
        $url = trim($url);
        if (!str_starts_with($url, 'https://')) {
            throw new ContentException('L\'adresse du dépôt doit commencer par « https:// ». Pour un dépôt privé, mettez un jeton dans l\'adresse.');
        }
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            throw new ContentException(sprintf('Adresse de dépôt illisible : %s', $url));
        }
        $allowed = array_values(array_filter(array_map('trim', explode(',', $this->allowlist))));
        if ([] !== $allowed && !\in_array($host, $allowed, true)) {
            throw new ContentException(sprintf('Hôte « %s » non autorisé (ENVIRONMENT_SOURCES_ALLOWLIST : %s).', $host, implode(', ', $allowed)));
        }

        return $url;
    }

    /** Un chemin relatif dans le dépôt, vérifié plutôt que nettoyé : il compose un chemin sur le disque. */
    private function checkSubdirectory(string $dossier): string
    {
        $dossier = trim(trim($dossier), '/');
        if ('' === $dossier) {
            return '';
        }
        foreach (explode('/', $dossier) as $segment) {
            if (1 !== preg_match('/^[A-Za-z0-9._-]+$/', $segment) || '.' === $segment || '..' === $segment) {
                throw new ContentException(sprintf('Sous-dossier « %s » invalide : un chemin relatif dans le dépôt, sans remontée.', $dossier));
            }
        }

        return $dossier;
    }

    private function clone(string $url, string $ref, string $destination): void
    {
        if (null === (new ExecutableFinder())->find('git')) {
            throw new ContentException('git est introuvable sur ce serveur : impossible d\'installer un environnement depuis un dépôt.');
        }
        // Tableau d'arguments, jamais de shell : une adresse ne peut pas devenir une commande.
        $command = ['git', 'clone', '--depth', '1', '--single-branch', '--no-tags'];
        if ('' !== $ref) {
            $command[] = '--branch';
            $command[] = $ref;
        }
        $this->run([...$command, '--', $url, $destination], null, self::CLONE_TIMEOUT, 'Clonage');
    }

    private function commitOf(string $clone): string
    {
        return trim($this->run(['git', 'rev-parse', 'HEAD'], $clone, 30, 'Lecture du commit'));
    }

    /** L'identifiant vient de l'environnement lui-même : c'est lui qui se nomme, pas l'administrateur. */
    private function readId(string $clone): string
    {
        $manifest = $clone.'/environment.yaml';
        if (!is_file($manifest)) {
            throw new ContentException('Ce dépôt n\'est pas un environnement : aucun environment.yaml à sa racine.');
        }
        $meta = Yaml::parseFile($manifest);
        if (!\is_array($meta) || !isset($meta['id']) || !\is_string($meta['id'])) {
            throw new ContentException('environment.yaml ne déclare pas d\'identifiant (« id »).');
        }

        return InstalledEnvironments::id($meta['id']);
    }

    private function checkSize(string $clone, string $id): void
    {
        $octets = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($clone, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $octets += $file->getSize() ?: 0;
        }
        $megaoctets = (int) round($octets / 1024 / 1024);
        if ($megaoctets > self::MAX_MEGABYTES) {
            throw new ContentException(sprintf('Environnement « %s » : %d Mo clonés, au-delà des %d Mo acceptés.', $id, $megaoctets, self::MAX_MEGABYTES));
        }
    }

    /** Un environnement installé ne masque jamais un autre : le registre refuserait de charger. */
    private function checkAvailable(string $id): void
    {
        if ($this->installed->has($id)) {
            return; // réinstallation du même : c'est la mise à jour.
        }
        if ($this->environments->has($id)) {
            throw new ContentException(sprintf('Un environnement « %s » existe déjà et ne vient pas d\'un dépôt : renommez le vôtre dans son environment.yaml.', $id));
        }
    }

    private function build(string $id): void
    {
        if (!is_file($this->buildScript)) {
            throw new ContentException(sprintf('Script d\'empaquetage introuvable (%s).', $this->buildScript));
        }
        $this->filesystem->mkdir($this->installed->artifactsDirectory());
        $this->run(
            [$this->buildScript, $id, $this->installed->artifactsDirectory()],
            null,
            self::BUILD_TIMEOUT,
            'Empaquetage',
            ['ENVIRONMENTS_PATH' => $this->environmentsPath, 'COMPOSER_ALLOW_SUPERUSER' => '1'],
        );
    }

    /** @param array<string, string> $env */
    private function run(array $command, ?string $cwd, int $timeout, string $etape, array $env = []): string
    {
        $process = new Process($command, $cwd, $env ?: null, timeout: $timeout);
        $process->run();
        if (!$process->isSuccessful()) {
            // La sortie d'erreur d'un git ou d'un composer dit précisément ce qui manque : on la garde,
            // tronquée, plutôt que de la remplacer par « échec ».
            $sortie = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new ContentException(sprintf('%s : échec. %s', $etape, mb_substr($sortie, -2000)));
        }

        return $process->getOutput();
    }

    private function record(string $id, string $url, string $ref, string $dossier, string $commit, string $state, string $message): void
    {
        $this->installed->write(new InstalledEnvironment($id, $url, $ref, $dossier, $commit, $state, $message, new \DateTimeImmutable()));
        $this->environments->reset();
    }
}
