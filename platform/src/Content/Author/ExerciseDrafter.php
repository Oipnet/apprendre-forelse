<?php

namespace App\Content\Author;

use App\Ai\ModelClient;
use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\Exercise;
use App\Content\Track;

/**
 * Brouillon d'exercice proposé par un modèle (API Anthropic).
 *
 * Facultatif : sans clé d'API, l'atelier fonctionne, la génération disparaît. Ce qui sort
 * d'ici n'est qu'un brouillon : c'est content:check qui décide si l'exercice tient debout.
 */
final class ExerciseDrafter
{
    /** L'outil impose la forme de la réponse : des fichiers, rien d'autre. */
    private const array OUTIL = [
        'name' => 'ecrire_exercice',
        'description' => 'Écrit les fichiers de l\'exercice.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'fichiers' => [
                    'type' => 'object',
                    'description' => 'Contenu complet de chaque fichier, par chemin relatif (exercise.yaml, instructions.md, starter/…, solution/…, tests/…).',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ],
            'required' => ['fichiers'],
        ],
    ];

    public function __construct(
        private readonly ModelClient $modele,
        private readonly ContentRepository $content,
        private readonly EnvironmentRegistry $environments,
        private readonly ExerciseFiles $fichiers,
    ) {
    }

    public function disponible(): bool
    {
        return $this->modele->disponible();
    }

    /**
     * Propose les fichiers d'un nouvel exercice.
     *
     * @param string        $sujet ce que l'exercice doit faire travailler, en français
     * @param Exercise|null $base  exercice dont l'état final sert de point de départ
     *
     * @return array<string, string>
     */
    public function brouillon(Track $track, string $id, string $titre, string $sujet, ?Exercise $base): array
    {
        $framework = $this->environments->get($base?->environment ?? $track->environment)->framework;

        return $this->demander([[
            'role' => 'user',
            'content' => implode("\n\n", array_filter([
                $this->presentationDuParcours($track),
                $base ? $this->etatDeLApplication($base, $framework) : 'L\'exercice part de l\'environnement nu, sans code préexistant.',
                $this->outilsDeTest($base?->environment ?? $track->environment),
                $this->exempleDExercice($track),
                sprintf(
                    "Écris maintenant l'exercice suivant.\n- identifiant : %s\n- titre : %s\n- base : %s\n- sujet : %s",
                    $id,
                    $titre,
                    $base?->id ?? '(aucune)',
                    $sujet,
                ),
            ])),
        ]], $framework);
    }

    /**
     * Repropose les fichiers d'un exercice à partir du rapport de content:check.
     *
     * @param array<string, string> $fichiers état actuel de l'exercice
     * @param list<string>          $erreurs  ce que content:check reproche
     *
     * @return array<string, string>
     */
    public function corriger(?Track $track, Exercise $exercise, array $fichiers, array $erreurs): array
    {
        $base = null === $exercise->base || null === $track ? null : $this->content->findExercise($track->id, $exercise->base);
        $framework = $this->environments->get($exercise->environment)->framework;

        return $this->demander([[
            'role' => 'user',
            'content' => implode("\n\n", array_filter([
                null === $track ? self::PRATIQUE : $this->presentationDuParcours($track),
                $base ? $this->etatDeLApplication($base, $framework) : null,
                $this->outilsDeTest($exercise->environment),
                "L'exercice actuel :\n\n".$this->enFichiers($fichiers),
                "content:check refuse cet exercice :\n- ".implode("\n- ", $erreurs),
                'Corrige l\'exercice et renvoie **tous** ses fichiers, y compris ceux qui ne changent pas.',
            ])),
        ]], $framework);
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     *
     * @return array<string, string>
     */
    private function demander(array $messages, string $framework): array
    {
        $entree = $this->modele->appeler($this->consignes($framework), $messages, self::OUTIL);
        if (!\is_array($entree['fichiers'] ?? null)) {
            throw new ContentException('Le modèle n\'a pas renvoyé de fichiers.');
        }

        return $this->valider($entree['fichiers']);
    }

    /**
     * @param array<mixed> $fichiers
     *
     * @return array<string, string>
     */
    private function valider(array $fichiers): array
    {
        $valides = [];
        foreach ($fichiers as $chemin => $contenu) {
            if (!\is_string($chemin) || !\is_string($contenu)) {
                throw new ContentException('Le modèle a renvoyé un fichier illisible.');
            }
            // Les mêmes règles que pour un auteur humain : pas de chemin hors de l'exercice.
            $this->fichiers->assertChemin($chemin);
            $valides[$chemin] = $contenu;
        }
        foreach (['exercise.yaml', 'instructions.md'] as $indispensable) {
            if (!isset($valides[$indispensable])) {
                throw new ContentException(sprintf('Le modèle a oublié %s.', $indispensable));
            }
        }

        return $valides;
    }

    /** Ce qu'un exercice de Pratique a de particulier, pour le modèle. */
    private const string PRATIQUE = <<<'TXT'
        Cet exercice appartient à la Pratique, pas à un parcours : un exercice court et brut, sans histoire ni
        personnage, pour se servir d'une fonctionnalité du framework. Le code de départ est écrit à l'ancienne ; les
        tests vérifient le comportement et l'usage de la fonctionnalité. Son exercise.yaml n'a ni « access », ni
        « base », ni « xp », mais « environment », « published » (AAAA-MM-JJ), « summary » (une phrase), et au besoin
        « version » (entre guillemets), « pull_request » (URL https) et « visibility ».
        TXT;

    private function presentationDuParcours(Track $track): string
    {
        $chapitres = [];
        foreach ($track->chapters as $chapitre) {
            $exercices = [];
            foreach ($chapitre->exerciseIds as $id) {
                $exercice = $this->content->findExercise($track->id, $id);
                $exercices[] = sprintf('    - %s — %s (%s)', $id, $exercice?->title, implode(', ', $exercice?->concepts ?? []));
            }
            $chapitres[] = sprintf("  %s — %s\n%s", $chapitre->id, $chapitre->title, implode("\n", $exercices));
        }

        return sprintf("Parcours « %s » : %s\n\n%s", $track->title, $track->description, implode("\n", $chapitres));
    }

    private function etatDeLApplication(Exercise $base, string $framework): string
    {
        $interessants = [];
        foreach ($this->content->solvedFiles($base) as $chemin => $contenu) {
            if (self::estDuCode($chemin, $framework)) {
                $interessants[$chemin] = $contenu;
            }
        }

        return sprintf(
            "État de l'application à la fin de l'exercice « %s » (le point de départ du nouvel exercice) :\n\n%s",
            $base->id,
            $this->enFichiers($interessants),
        );
    }

    /** Les traits que les tests d'exercices utilisent : ils vivent dans l'environnement, pas dans le pack. */
    private function outilsDeTest(string $environnement): string
    {
        $outils = [];
        $dossier = $this->environments->get($environnement)->directory.'/tests/Formation';
        foreach (glob($dossier.'/*.php') ?: [] as $fichier) {
            $outils['tests/Formation/'.basename($fichier)] = (string) file_get_contents($fichier);
        }

        return $outils ? "Outils de test fournis par l'environnement, utilisables dans tests/ :\n\n".$this->enFichiers($outils) : '';
    }

    private function exempleDExercice(Track $track): string
    {
        $exercices = $this->content->exercisesOf($track);
        $modele = end($exercices);

        return $modele instanceof Exercise
            ? "Un exercice existant, à prendre pour modèle de style et de format :\n\n".$this->enFichiers($this->fichiers->read($modele->directory))
            : '';
    }

    /** @param array<string, string> $fichiers */
    private function enFichiers(array $fichiers): string
    {
        $blocs = [];
        foreach ($fichiers as $chemin => $contenu) {
            $blocs[] = sprintf("=== %s ===\n%s", $chemin, $contenu);
        }

        return implode("\n\n", $blocs);
    }

    /** Le code qui décrit l'état de l'application, hors tests, assets et fichiers de départ du framework. */
    public static function estDuCode(string $chemin, string $framework): bool
    {
        $dossiers = match ($framework) {
            'laravel' => ['app/', 'routes/', 'resources/views/', 'database/migrations/', 'database/seeders/', 'database/factories/', 'config/'],
            // Pour Docker, « le code », ce sont les fichiers d'infrastructure autant que l'application.
            'docker' => ['Dockerfile', 'compose.', 'docker-compose.', '.dockerignore', 'docker/', 'public/', 'src/', '.env'],
            default => ['src/', 'config/packages/', 'templates/'],
        };
        foreach ($dossiers as $dossier) {
            if (str_starts_with($chemin, $dossier)) {
                return true;
            }
        }

        return false;
    }

    private function consignes(string $framework): string
    {
        $nom = match ($framework) { 'laravel' => 'Laravel', 'docker' => 'Docker', default => 'Symfony' };
        $conventions = match ($framework) { 'laravel' => self::CONVENTIONS_LARAVEL, 'docker' => self::CONVENTIONS_DOCKER, default => self::CONVENTIONS_SYMFONY };

        return str_replace(['{framework}', '{conventions}'], [$nom, $conventions], <<<'TEXTE'
            Tu écris un exercice pour une formation {framework} où l'apprenant code dans son navigateur
            (PHP 8.4 compilé en WebAssembly). Tu réponds uniquement en appelant l'outil ecrire_exercice.

            Les fichiers d'un exercice :
            - exercise.yaml : id, title, concepts, xp, base, open, preview, setup, editable, readonly,
              objectives (une entrée par méthode de test : {test, label}), hints (3 indices gradués :
              le premier oriente, le dernier donne la réponse), docs ({title, url} vers la documentation
              officielle de {framework} ou le manuel PHP — uniquement des pages qui existent),
              et, si l'exercice fait écrire des tests à l'apprenant, mutants ({id, label, changes}).
            - instructions.md : les consignes, en français, dans le fil rouge de la formation.
            - starter/ : les fichiers de départ, avec des TODO.
            - solution/ : la solution de référence, qui ne contient que les fichiers modifiés.
            - tests/ : les tests PHPUnit cachés, une méthode par objectif.

            Règles impératives :
            - Les tests doivent échouer sur l'état de départ et passer avec la solution. Aucun objectif
              ne doit être déjà validé au départ : c'est vérifié par content:check.
            - Les messages d'échec des tests s'adressent à l'apprenant : ils expliquent ce qui est attendu.
            - Tout est en français : consignes, labels, commentaires, messages. Le code suit les bonnes
              pratiques de {framework}.
            - php-wasm n'a pas l'extension intl : évite ce qui en dépend (MoneyType, NumberType sans
              html5, dates localisées).
            - Reste dans l'univers du parcours et dans la continuité des exercices précédents : mêmes
              entités, mêmes routes, même ton.
            - N'invente pas d'API {framework} : si tu hésites, reste sur ce qu'utilisent déjà les exercices
              existants.
            - `preview:` est un chemin de l'application (`/menu`, `/api/plats`) ; ne mets jamais `null`.
            - N'écris `mutants:` que si l'exercice fait écrire des tests à l'apprenant ; sinon, omets la clé.

            {conventions}
            TEXTE);
    }

    private const string CONVENTIONS_SYMFONY = <<<'TEXTE'
            Conventions de ce projet (Symfony 8.1), à respecter même si tu connais d'autres façons de faire :
            - Attributs PHP, autowiring, services ; pas de YAML de routage.
            - Les commandes console sont **invocables** : une classe `final` avec `#[AsCommand]` et une
              méthode `__invoke(SymfonyStyle $io, ...)`. Elles n'étendent jamais `Command` et n'ont ni
              `configure()` ni `execute()`. Les entrées se déclarent en arguments de `__invoke` :
              `#[Argument('Description')] ?string $guilde = null` et `#[Option('Description')] int $jours = 7`
              (`Symfony\Component\Console\Attribute\Argument` et `…\Attribute\Option`). Le retour est
              `Command::SUCCESS`.
            - Les dépendances arrivent par le constructeur, en propriétés promues `private readonly`.
            - Dans les tests, `enregistrer(...$objets)` vide l'EntityManager après coup : enregistre des
              entités liées entre elles **en un seul appel**, sinon Doctrine refuse une association vers
              une entité détachée.
            TEXTE;

    private const string CONVENTIONS_DOCKER = <<<'TEXTE'
            Conventions de ce projet (Docker simulé), à respecter même si tu connais d'autres façons de faire :
            - Docker ne tourne pas vraiment : un simulateur en PHP (Forelse\DockerSim) construit les images,
              lance les conteneurs et exécute pour de vrai le PHP qu'ils servent (Apache, nginx + php-fpm,
              php -S). Catalogue d'images fermé : php (cli, fpm, apache, alpine), composer, nginx, postgres,
              mysql, mariadb, redis, node, alpine, debian, caddy, axllent/mailpit, adminer. Les paquets apk/apt
              et les extensions PHP (docker-php-ext-install, pecl) sont simulés avec leurs dépendances.
            - Les fichiers de l'apprenant : Dockerfile, compose.yaml, .dockerignore, .env, docker/ (configuration
              nginx, php.ini, scripts d'entrée), et la petite application PHP (public/, src/).
            - Les tests cachés étendent `Forelse\DockerSim\Testing\DockerTestCase` : `$this->build('tag')`
              (BuildResult : success, output, image, steps, warnings), `$this->dockerOk('run -d -p 8080:80 tag')`
              ou `$this->dockerOk('compose up -d')` (n'importe quelle commande docker), `$this->http('localhost:8080/')`
              (HttpResponse : status, body, error, trace), `$this->container('nom')`, `$this->service('web')`,
              `$this->image('tag')`, `$this->exec($conteneur, 'commande shell')`, `$this->dockerfile()` et
              `$this->instructions('COPY')` pour lire le Dockerfile, `$this->compose()` pour le projet compose.
              Assertions : assertBuildSucceeded, assertImageHasFile, assertImageLacksFile, assertImageSizeBelow,
              assertRunning, assertPageContains. Chaque test part d'un démon vierge.
            - Le code PHP servi par les conteneurs s'exécute dans le processus des tests : pas d'exit(), pas de
              fonction globale déclarée dans public/index.php (utilise des classes autoloadées).
            - `preview:` est l'adresse visitée dans l'aperçu : `/localhost:8080/`. `setup:` liste des commandes
              docker (`build -t criee .`) jouées au chargement.
            - Documentation : https://docs.docker.com/reference/dockerfile/, https://docs.docker.com/reference/compose-file/,
              https://hub.docker.com/_/php.
            TEXTE;

    private const string CONVENTIONS_LARAVEL = <<<'TEXTE'
            Conventions de ce projet (Laravel 13), à respecter même si tu connais d'autres façons de faire :
            - Routes dans `routes/web.php` (`Route::get('/x', [XController::class, 'index'])->name('x.index')`),
              contrôleurs dans `app/Http/Controllers`, vues Blade dans `resources/views`, modèles Eloquent
              dans `app/Models`, migrations dans `database/migrations`, validation par `$request->validate()`
              ou une Form Request.
            - Les tests cachés étendent `Tests\TestCase` : `$this->get()`, `$this->post()`, `(string) $this->view()`,
              `route('nom', absolute: false)`, `Symfony\Component\DomCrawler\Crawler` sur `$response->content()`
              pour la structure HTML, `RefreshDatabase` dès qu'il y a une base (SQLite en mémoire). La
              vérification CSRF est désactivée pendant les tests par Laravel lui-même.
            - `setup:` liste des commandes artisan (`migrate --force`, `db:seed`) lancées à chaque chargement
              de l'aperçu, sur une base vide. Pas de `proc_open` ni de réseau dans le navigateur.
            - Documentation : https://laravel.com/docs/13.x/<page> (routing, controllers, blade, requests,
              validation, eloquent, migrations…). Messages de validation déjà traduits en français.
            TEXTE;
}
