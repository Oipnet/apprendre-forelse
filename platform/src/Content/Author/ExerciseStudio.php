<?php

namespace App\Content\Author;

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
    ) {
    }

    /** @return array<string, string> contenu par chemin relatif */
    public function lire(Exercise $exercise): array
    {
        return $this->fichiers->read($exercise->directory);
    }

    /** Le dossier du parcours, ou du pack pour un exercice de Pratique, accepte-t-il l'écriture ? */
    public function modifiable(Track|Pack $owner): bool
    {
        return is_writable($owner->directory);
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
        $this->assertModifiable($owner);
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
        $this->assertModifiable($track);
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

        (new Filesystem())->mkdir($directory);
        $framework = $this->environments->get($this->content->findChapter($track, $chapitreId)?->environment ?? $track->environment)->framework;
        $this->fichiers->write($directory, $brouillon ?? $this->squelette($id, $titre, $base, $framework));
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
        $this->assertModifiable($pack);
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
        $framework = $this->environments->get($environment)->framework;

        (new Filesystem())->mkdir($directory);
        $this->fichiers->write($directory, $this->squelettePratique($id, $titre, $environment, $framework));
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
        $this->assertModifiable($owner);
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

    private function assertModifiable(Track|Pack $owner): void
    {
        if (!$this->modifiable($owner)) {
            throw new ContentException(sprintf('%s « %s » n\'est pas modifiable (dossier en lecture seule).', $owner instanceof Pack ? 'Le pack' : 'Le parcours', $owner->id));
        }
    }

    /** @return array<string, string> */
    private function squelette(string $id, string $titre, ?string $base, string $framework): array
    {
        if ('docker' === $framework) {
            return $this->squeletteDocker($id, $titre, $base);
        }
        $laravel = 'laravel' === $framework;
        $controleur = $laravel ? 'app/Http/Controllers/MonControleur.php' : 'src/Controller/MonControleur.php';
        $yaml = <<<YAML
            id: {$id}
            title: {$titre}
            concepts: []
            xp: 200

            YAML;
        if (null !== $base) {
            $yaml .= "base: {$base}\n";
        }
        $yaml .= <<<YAML

            open: {$controleur}
            preview: /

            editable:
              - {$controleur}

            objectives:
              - test: testLaPageRepond
                label: La page répond

            hints:
              - Premier indice.
            YAML;

        return [
            'exercise.yaml' => $yaml."\n",
            'instructions.md' => "# {$titre}\n\nÀ écrire.\n",
            'starter/'.$controleur => "<?php\n\nnamespace App\\".($laravel ? 'Http\\Controllers' : 'Controller').";\n\n// TODO : le code de départ de l'apprenant\n",
            'solution/'.$controleur => "<?php\n\nnamespace App\\".($laravel ? 'Http\\Controllers' : 'Controller').";\n\n// TODO : la solution\n",
            ...($laravel ? ['tests/Formation/MonTest.php' => "<?php\n\nnamespace Tests\\Formation;\n\nuse Tests\\TestCase;\n\nclass MonTest extends TestCase\n{\n    public function testLaPageRepond(): void\n    {\n        \$this->get('/')->assertOk();\n    }\n}\n"] : []),
            ...($laravel ? [] : ['tests/Taverne/MonTest.php' => "<?php\n\nnamespace App\\Tests\\Taverne;\n\nuse Symfony\\Bundle\\FrameworkBundle\\Test\\WebTestCase;\n\nclass MonTest extends WebTestCase\n{\n    public function testLaPageRepond(): void\n    {\n        \$client = static::createClient();\n        \$client->request('GET', '/');\n\n        \$this->assertResponseIsSuccessful();\n    }\n}\n"]),
        ];
    }

    /**
     * Le squelette d'un exercice de parcours, sans ce qui n'a pas cours en Pratique (XP, fil rouge) et avec ses clés.
     *
     * @return array<string, string>
     */
    private function squelettePratique(string $id, string $titre, string $environment, string $framework): array
    {
        $cles = sprintf("environment: %s\npublished: %s\nsummary: À écrire, en une phrase.\n# version: '8.1'\n# pull_request: https://github.com/…\n# Retirez cette ligne pour publier l'exercice.\nvisibility: admin\n", $environment, date('Y-m-d'));
        $fichiers = [];
        foreach ($this->squelette($id, $titre, null, $framework) as $chemin => $contenu) {
            $contenu = str_replace(['App\\Tests\\Taverne', 'namespace Tests\\Formation;'], ['App\\Tests\\Pratique', 'namespace Tests\\Pratique;'], $contenu);
            $fichiers[str_replace(['tests/Taverne/', 'tests/Formation/'], 'tests/Pratique/', $chemin)] = 'exercise.yaml' === $chemin ? str_replace("xp: 200\n", $cles, $contenu) : $contenu;
        }

        return $fichiers;
    }

    /** @return array<string, string> */
    private function squeletteDocker(string $id, string $titre, ?string $base): array
    {
        $yaml = "id: {$id}\ntitle: {$titre}\nconcepts: []\nxp: 200\n";
        if (null !== $base) {
            $yaml .= "base: {$base}\n";
        }
        $yaml .= "\nopen: Dockerfile\npreview: /localhost:8080/\n\neditable:\n  - Dockerfile\n\nobjectives:\n  - test: testLImageSeConstruit\n    label: L'image se construit\n\nhints:\n  - Premier indice.\n";

        return [
            'exercise.yaml' => $yaml,
            'instructions.md' => "# {$titre}\n\nÀ écrire.\n",
            'starter/Dockerfile' => "# TODO : le Dockerfile de départ de l'apprenant\n",
            'solution/Dockerfile' => "FROM php:8.4-apache\nCOPY public/ /var/www/html/\n",
            'tests/Docker/MonTest.php' => "<?php\n\nnamespace Tests\\Docker;\n\nuse Forelse\\DockerSim\\Testing\\DockerTestCase;\n\nclass MonTest extends DockerTestCase\n{\n    public function testLImageSeConstruit(): void\n    {\n        \$this->assertBuildSucceeded(\$this->build('app'));\n    }\n}\n",
        ];
    }
}
