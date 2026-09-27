<?php

namespace App\Content\Author;

use App\Content\Author\Scaffold\ExerciseScaffolders;
use App\Content\Check\CheckResult;
use App\Content\Check\ExerciseChecker;
use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\Exercise;
use App\Content\Pack;
use App\Content\Track;
use Symfony\Component\Filesystem\Filesystem;

/**
 * L'atelier des auteurs : lire, enregistrer, créer et vérifier un exercice d'un pack.
 *
 * L'atelier travaille sur le format lui-même (exercise.yaml, instructions.md, fichiers) :
 * rien n'est caché derrière un formulaire, et ce qui est écrit ici est exactement ce que
 * content:check vérifie.
 */
final class ExerciseStudio
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly ExerciseChecker $checker,
        private readonly ExerciseFiles $fichiers,
        private readonly TrackWriter $trackWriter,
        private readonly EnvironmentRegistry $environments,
        private readonly ExerciseScaffolders $scaffolders,
    ) {
    }

    /**
     * L'exercice et ce qui le contient : son parcours, ou son pack pour un exercice de Pratique ($trackId null).
     *
     * Sans le filtre de PracticeVisibility (ExerciseLocator) : l'atelier sert à écrire les exercices en préparation
     * ou programmés, qu'un auteur doit donc trouver même s'il n'est pas administrateur.
     *
     * @return array{Track|Pack, Exercise}|null
     */
    public function trouver(?string $trackId, string $exerciseId): ?array
    {
        if (null === $trackId) {
            $practice = $this->content->findPractice($exerciseId);

            return null === $practice ? null : [$this->content->packs()[$practice->packId], $practice->exercise];
        }
        $track = $this->content->findTrack($trackId);
        $exercise = null === $track ? null : $this->content->findExercise($trackId, $exerciseId);

        return null === $exercise ? null : [$track, $exercise];
    }

    /** Le fichier du parcours modifié le plus récemment : « où en étais-je ? » sans ouvrir un terminal. */
    public function modifieLe(Track $track): ?\DateTimeImmutable
    {
        if (!is_dir($track->directory)) {
            return null;
        }
        $dernier = 0;
        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($track->directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($fichiers as $fichier) {
            $dernier = max($dernier, $fichier->getMTime());
        }

        return $dernier > 0 ? (new \DateTimeImmutable())->setTimestamp($dernier) : null;
    }

    /** @return array<string, string> contenu par chemin relatif */
    public function lire(Exercise $exercise): array
    {
        return $this->fichiers->read($exercise->directory);
    }

    /**
     * Les environnements où créer un exercice de Pratique : ceux déjà utilisés par le contenu installé, dont
     * l'atelier sait échafauder le framework.
     *
     * @return list<string>
     */
    public function environnementsPratique(): array
    {
        $environnements = [];
        foreach ($this->content->tracks() as $track) {
            $environnements[] = $track->environment;
            foreach ($track->chapters as $chapitre) {
                $environnements[] = $chapitre->environment;
            }
        }
        foreach ($this->content->practices() as $practice) {
            $environnements[] = $practice->exercise->environment;
        }
        $environnements = array_filter(
            array_unique(array_filter($environnements)),
            fn (string $id) => $this->environments->has($id) && $this->scaffolders->has($this->environments->get($id)->framework),
        );
        sort($environnements);

        return $environnements;
    }

    /** Le dossier du parcours, ou du pack pour un exercice de Pratique, accepte-t-il l'écriture ? */
    public function modifiable(Track|Pack $owner): bool
    {
        return PackWritability::writable($owner);
    }

    /**
     * Enregistre les fichiers puis relit l'exercice : les erreurs de format remontent tout de suite.
     *
     * @param array<string, string> $fichiers
     *
     * @return string|null le message d'erreur du format, ou null si tout est bon
     */
    public function enregistrer(Track|Pack $owner, Exercise $exercise, array $fichiers): ?string
    {
        PackWritability::assert($owner);
        $this->fichiers->write($exercise->directory, $fichiers);

        return $this->relire($exercise->trackId, $exercise->id);
    }

    /**
     * Crée un exercice vide (mais valide) et l'inscrit à la fin d'un chapitre.
     *
     * @param array<string, string>|null $brouillon fichiers déjà rédigés (proposés par un modèle)
     *
     * @return string l'identifiant de l'exercice créé
     */
    public function creer(Track $track, string $chapitreId, string $id, string $titre, ?string $base, ?array $brouillon = null): string
    {
        PackWritability::assert($track);
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $id)) {
            throw new ContentException(sprintf('Identifiant « %s » : uniquement des minuscules, des chiffres et des tirets.', $id));
        }
        $directory = $track->directory.'/exercises/'.$id;
        if (is_dir($directory)) {
            throw new ContentException(sprintf('L\'exercice « %s » existe déjà.', $id));
        }
        if (null !== $base && null === $this->content->findExercise($track->id, $base)) {
            throw new ContentException(sprintf('La base « %s » n\'est pas un exercice de ce parcours.', $base));
        }

        $fichiers = $brouillon ?? $this->scaffolders->get($this->environments->get($this->content->findChapter($track, $chapitreId)->environment ?? $track->environment)->framework)
            ->files($this->entete($id, $titre, $base, null), $titre, false);

        (new Filesystem())->mkdir($directory);
        $this->fichiers->write($directory, $fichiers);
        $this->trackWriter->ajouterExercice($track, $chapitreId, $id);
        $this->content->reset();

        return $id;
    }

    /**
     * Crée un exercice de Pratique vide (mais valide), en préparation (`visibility: admin`) : il n'apparaît
     * aux apprenants qu'une fois la clé retirée.
     *
     * @return string l'identifiant de l'exercice créé
     */
    public function creerPratique(Pack $pack, string $id, string $titre, string $environment): string
    {
        PackWritability::assert($pack);
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $id)) {
            throw new ContentException(sprintf('Identifiant « %s » : uniquement des minuscules, des chiffres et des tirets.', $id));
        }
        if (null !== $this->content->findPractice($id)) {
            throw new ContentException(sprintf('L\'exercice de Pratique « %s » existe déjà.', $id));
        }
        $directory = $pack->directory.'/practice/'.$id;
        if (is_dir($directory)) {
            throw new ContentException(sprintf('Le dossier practice/%s existe déjà dans le pack « %s ».', $id, $pack->id));
        }
        $fichiers = $this->scaffolders->get($this->environments->get($environment)->framework)
            ->files($this->entete($id, $titre, null, $environment), $titre, true);

        (new Filesystem())->mkdir($directory);
        $this->fichiers->write($directory, $fichiers);
        $this->content->reset();

        return $id;
    }

    /**
     * Supprime un exercice : son dossier, et sa ligne dans track.yaml (un exercice de Pratique n'a que son dossier).
     *
     * Un exercice qui sert de base à un autre ne peut pas partir : le fil rouge se casserait.
     */
    public function supprimer(Track|Pack $owner, Exercise $exercise): void
    {
        PackWritability::assert($owner);
        if ($owner instanceof Pack) {
            (new Filesystem())->remove($exercise->directory);
            $this->content->reset();

            return;
        }
        $track = $owner;
        foreach ($this->content->exercisesOf($track) as $autre) {
            if ($autre->base === $exercise->id) {
                throw new ContentException(sprintf('Impossible : « %s » part de cet exercice.', $autre->id));
            }
        }

        (new Filesystem())->remove($exercise->directory);
        $this->trackWriter->retirerExercice($track, $exercise->id);
        $this->content->reset();
    }

    public function verifier(Exercise $exercise): CheckResult
    {
        return $this->checker->check($exercise);
    }

    private function relire(?string $trackId, string $exerciceId): ?string
    {
        $this->content->reset();
        try {
            null === $trackId ? $this->content->findPractice($exerciceId) : $this->content->findExercise($trackId, $exerciceId);
        } catch (ContentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Le début d'exercise.yaml, commun à tous les frameworks : un exercice de Pratique n'a ni XP ni fil rouge, mais ses clés.
     */
    private function entete(string $id, string $titre, ?string $base, ?string $environnementPratique): string
    {
        $yaml = "id: {$id}\ntitle: {$titre}\nconcepts: []\n";
        if (null !== $environnementPratique) {
            return $yaml.sprintf("environment: %s\npublished: %s\nsummary: À écrire, en une phrase.\n# version: '8.1'\n# pull_request: https://github.com/…\n# Retirez cette ligne pour publier l'exercice.\nvisibility: admin\n", $environnementPratique, date('Y-m-d'));
        }

        return $yaml."xp: 200\n".(null !== $base ? "base: {$base}\n" : '');
    }
}
