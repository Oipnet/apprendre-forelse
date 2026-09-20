<?php

namespace App\Instance;

use App\Content\EnvironmentRegistry;

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
 */
final readonly class PackEnvironments
{
    public function __construct(
        private EnvironmentRegistry $environments,
        private EnvironmentInstaller $installer,
        private EnvironmentArtifacts $artifacts,
    ) {
    }

    /**
     * Ce que les packs portent, et si c'est prêt à jouer.
     *
     * @return list<array{id: string, directory: string, built: bool}>
     */
    public function state(): array
    {
        $lignes = [];
        foreach ($this->environments->carried() as $id => $directory) {
            $lignes[] = [
                'id' => $id,
                'directory' => $directory,
                'built' => null !== $this->artifacts->path($id.'.zip'),
            ];
        }

        return $lignes;
    }

    /**
     * Ceux dont l'archive manque encore.
     *
     * @return list<string> identifiants, dans l'ordre alphabétique
     */
    public function toBuild(): array
    {
        $aFaire = [];
        foreach ($this->state() as $ligne) {
            if (!$ligne['built']) {
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
                $built[] = $id;
            } catch (\Throwable $e) {
                $failed[$id] = $e->getMessage();
            }
        }

        return ['built' => $built, 'failed' => $failed];
    }
}
