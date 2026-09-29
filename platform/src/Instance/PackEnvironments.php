<?php

namespace App\Instance;

use App\Content\ContentException;
use App\Content\EnvironmentRegistry;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Les environnements que les packs **portent** (`<pack>/environments/<id>/`), et leur empaquetage.
 *
 * Un décor appartient au contenu qui le met en scène : il arrive avec son pack, se versionne avec lui,
 * et disparaît avec lui. Rien à déclarer, rien à cloner — il ne lui manque que son archive : le projet
 * zippé que le navigateur télécharge, et l'index de complétion que l'éditeur propose.
 *
 * **Jamais pendant une requête web.** Empaqueter, c'est lancer `composer install` : cela dure des
 * minutes et exécute le code du pack. Ce service ne fait que lire, sauf quand on l'appelle depuis la
 * commande `app:environnement:synchroniser` ou depuis l'administration — c'est-à-dire depuis un geste
 * d'exploitation, jamais depuis la visite d'un apprenant.
 *
 * Une archive se refait quand ses sources changent : une nouvelle version du pack qui touche le décor, ou
 * le socle qu'il prolonge (`extends:`). Sans cela, l'apprenant téléchargerait l'ancien projet pendant que
 * content:check et la vérification côté serveur liraient le nouveau. L'empreinte des sources (voir
 * fingerprint()) est notée à côté de l'archive à chaque empaquetage.
 */
final readonly class PackEnvironments
{
    public function __construct(
        private EnvironmentRegistry $environments,
        private EnvironmentInstaller $installer,
        private EnvironmentArtifacts $artifacts,
        private InstalledEnvironments $installed,
    ) {
    }

    /** Ce qu'installe l'empaquetage dans les dossiers, et ce qui n'est pas une source : hors de l'empreinte. */
    private const array NOT_SOURCES = ['vendor', 'node_modules', 'var', '.git'];

    /**
     * Ce que les packs portent, et si c'est prêt à jouer.
     *
     * `stale` : l'archive existe, mais ses sources ont changé depuis (ou son empreinte n'a jamais été notée).
     *
     * @return list<array{id: string, directory: string, built: bool, stale: bool}>
     */
    public function state(): array
    {
        $lignes = [];
        foreach ($this->environments->carried() as $id => $directory) {
            $built = null !== $this->artifacts->path(EnvironmentArtifacts::archiveName($id));
            $lignes[] = [
                'id' => $id,
                'directory' => $directory,
                'built' => $built,
                'stale' => $built && $this->fingerprint($id) !== $this->recordedFingerprint($id),
            ];
        }

        return $lignes;
    }

    /**
     * Ceux dont l'archive manque encore, ou n'est plus à jour de ses sources.
     *
     * @return list<string> identifiants, dans l'ordre alphabétique
     */
    public function toBuild(): array
    {
        $aFaire = [];
        foreach ($this->state() as $ligne) {
            if (!$ligne['built'] || $ligne['stale']) {
                $aFaire[] = $ligne['id'];
            }
        }
        sort($aFaire);

        return $aFaire;
    }

    /**
     * Empaquette ce qui ne l'est pas encore. Rend ce qui a été fait, et ce qui a échoué.
     *
     * Un échec n'arrête pas les autres : trois décors dont un ne compile pas, c'est deux archives et un
     * message, pas rien du tout.
     *
     * Une seule passe suffit, et l'ordre n'a pas d'importance : un environnement qui en prolonge un
     * autre trouve sa base **sur le disque**, arrivée avec le pack. `extends:` se résout entre
     * dossiers, pas entre archives — il n'y a donc pas de dépendance d'empaquetage à ordonner.
     *
     * @param callable(string): void|null $progress
     *
     * @return array{built: list<string>, failed: array<string, string>}
     */
    public function synchronize(?callable $progress = null): array
    {
        $say = $progress ?? static fn (string $message) => null;
        $built = [];
        $failed = [];

        foreach ($this->toBuild() as $id) {
            $say(sprintf('Environnement « %s », porté par un pack : empaquetage…', $id));
            try {
                $this->installer->build($id);
                // Après l'empaquetage : composer install peut avoir écrit un composer.lock dans le dossier.
                $fingerprint = $this->fingerprint($id);
                if (null !== $fingerprint) {
                    (new Filesystem())->dumpFile($this->fingerprintFile($id), $fingerprint);
                }
                $built[] = $id;
            } catch (\Throwable $e) {
                $failed[$id] = $e->getMessage();
            }
        }

        return ['built' => $built, 'failed' => $failed];
    }

    /**
     * L'empreinte des sources d'un environnement : le contenu de chaque fichier de sa chaîne `extends:`, de la base
     * à lui-même. Le contenu, pas la date : un pack recopié à chaque déploiement (image Docker) garde son empreinte, et
     * ne relance pas des minutes de composer install pour rien. Null si la chaîne ne se résout pas (base
     * introuvable) : l'empaquetage le dira.
     */
    public function fingerprint(string $id): ?string
    {
        try {
            $directories = $this->environments->get($id)->directories;
        } catch (ContentException) {
            return null;
        }
        $context = hash_init('xxh128');
        foreach ($directories as $index => $directory) {
            $files = (new Finder())->files()->in($directory)->ignoreDotFiles(false)->ignoreVCS(true)
                ->exclude(self::NOT_SOURCES)->sortByName();
            foreach ($files as $file) {
                hash_update($context, $index."\0".str_replace('\\', '/', $file->getRelativePathname())."\0".hash_file('xxh128', $file->getPathname())."\n");
            }
        }

        return hash_final($context);
    }

    private function recordedFingerprint(string $id): ?string
    {
        $file = $this->fingerprintFile($id);

        return is_file($file) ? trim((string) file_get_contents($file)) : null;
    }

    private function fingerprintFile(string $id): string
    {
        return $this->installed->artifactsDirectory().'/'.$id.'.sources';
    }
}
