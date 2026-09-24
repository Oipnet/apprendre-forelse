<?php

namespace App\Tests\Content;

use App\Content\Access;
use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ContentRepositoryTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/content-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    private function repository(string ...$paths): ContentRepository
    {
        return new ContentRepository($paths, new EnvironmentRegistry(self::ROOT.'/environments'), new Version(self::ROOT.'/VERSION'));
    }

    public function testChargeLePackDeDemo(): void
    {
        $repository = $this->repository(self::ROOT.'/examples/packs');

        $this->assertArrayHasKey('demo', $repository->packs());
        $track = $repository->findTrack('decouverte');
        $this->assertNotNull($track);
        $this->assertSame('symfony-8', $track->environment);
        $this->assertSame(['01-bonjour', '02-bonjour-prenom'], array_map(fn ($e) => $e->id, $repository->exercisesOf($track)));
    }

    public function testUnExerciceNePeutPasSAppelerAcheter(): void
    {
        $directory = sys_get_temp_dir().'/pack-acheter-'.bin2hex(random_bytes(4));
        $files = [
            'pack.yaml' => "id: p\ntitle: P\ntracks: [t]",
            'tracks/t/track.yaml' => "id: t\ntitle: T\nenvironment: symfony-8\nchapters:\n  - {id: c, title: C, exercises: [acheter]}",
            'tracks/t/exercises/acheter/exercise.yaml' => "id: acheter\ntitle: A\neditable: [a.php]\nobjectives: [{test: testA, label: A}]",
            'tracks/t/exercises/acheter/instructions.md' => 'Consignes',
        ];
        foreach ($files as $path => $content) {
            @mkdir(\dirname($directory.'/'.$path), 0777, true);
            file_put_contents($directory.'/'.$path, $content);
        }

        try {
            $this->expectException(\App\Content\ContentException::class);
            $this->expectExceptionMessage('réservé à la page d\'achat');
            $this->repository($directory)->tracks();
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($directory);
        }
    }

    public function testLaCleAccessEstDepreciee(): void
    {
        $repository = $this->repository(self::ROOT.'/examples/packs', __DIR__.'/../Fixtures/packs/enchainement');

        $this->assertSame(Access::Free, $repository->findExercise('debut', 'e1')?->access, 'Encore lue, pour ne casser aucun pack.');
        $this->assertArrayHasKey('debut/e1', $repository->deprecations(), 'Mais signalée par content:check.');
        $this->assertArrayNotHasKey('decouverte/02-bonjour-prenom', $repository->deprecations(), 'Un exercice sans la clé n\'est pas concerné.');
    }

    public function testUneBasePartDeLEtatFinalDeLExercicePrecedent(): void
    {
        $repository = $this->repository(self::ROOT.'/examples/packs');
        $first = $repository->findExercise('decouverte', '01-bonjour');
        $second = $repository->findExercise('decouverte', '02-bonjour-prenom');

        $this->assertStringContainsString('TODO', $repository->startingFiles($first)['src/Controller/BonjourController.php']);
        $this->assertSame(
            $repository->solutionFiles($first)['src/Controller/BonjourController.php'],
            $repository->startingFiles($second)['src/Controller/BonjourController.php'],
        );
    }

    public function testTestsEtEnchainement(): void
    {
        $repository = $this->repository(self::ROOT.'/examples/packs');
        $first = $repository->findExercise('decouverte', '01-bonjour');

        $this->assertSame(['tests/BonjourTest.php'], array_keys($repository->testFiles($first)));
        $this->assertSame('02-bonjour-prenom', $repository->next($first)?->id);
        $this->assertNull($repository->next($repository->next($first)));
        $this->assertNull($repository->findExercise('decouverte', 'inexistant'));
    }

    public function testEnvironnementDeChapitreEtSetup(): void
    {
        $filesystem = new Filesystem();
        foreach ([
            'pack.yaml' => "id: p\ntitle: P\ntracks: [t]",
            'tracks/t/track.yaml' => "id: t\ntitle: T\nenvironment: symfony-8\nchapters:\n  - {id: c1, title: C1, exercises: [e1]}\n  - {id: c2, title: C2, environment: symfony-8-doctrine, exercises: [e2]}",
            'tracks/t/exercises/e1/exercise.yaml' => "id: e1\ntitle: E1\neditable: [a.php]\nobjectives: [{test: testA, label: A}]",
            'tracks/t/exercises/e1/instructions.md' => 'Consignes',
            'tracks/t/exercises/e2/exercise.yaml' => "id: e2\ntitle: E2\neditable: [a.php]\nsetup: ['doctrine:schema:update --force']\nobjectives: [{test: testA, label: A}]",
            'tracks/t/exercises/e2/instructions.md' => 'Consignes',
        ] as $path => $content) {
            $filesystem->dumpFile($this->tmp.'/p/'.$path, $content);
        }
        $repository = $this->repository($this->tmp);

        $this->assertSame('symfony-8', $repository->findExercise('t', 'e1')->environment);
        $this->assertSame('symfony-8-doctrine', $repository->findExercise('t', 'e2')->environment, 'Le chapitre impose son environnement.');
        $this->assertSame(['doctrine:schema:update --force'], $repository->findExercise('t', 'e2')->setup);
    }

    public function testVisibiliteDUnParcours(): void
    {
        $filesystem = new Filesystem();
        foreach ([
            'a/p/pack.yaml' => "id: p\ntitle: P\ntracks: [t, u]",
            'a/p/tracks/t/track.yaml' => "id: t\ntitle: T\nenvironment: symfony-8\nvisibility: admin\nchapters:\n  - {id: c, title: C, exercises: []}",
            'a/p/tracks/u/track.yaml' => "id: u\ntitle: U\nenvironment: symfony-8\nchapters:\n  - {id: c, title: C, exercises: []}",
            'b/q/pack.yaml' => "id: q\ntitle: Q\ntracks: [v]",
            'b/q/tracks/v/track.yaml' => "id: v\ntitle: V\nenvironment: symfony-8\nvisibility: secret\nchapters:\n  - {id: c, title: C, exercises: []}",
        ] as $path => $content) {
            $filesystem->dumpFile($this->tmp.'/'.$path, $content);
        }
        $repository = $this->repository($this->tmp.'/a');

        $this->assertTrue($repository->findTrack('t')->isRestricted(), '« visibility: admin » réserve le parcours aux administrateurs.');
        $this->assertFalse($repository->findTrack('u')->isRestricted(), 'Sans la clé, un parcours est public.');

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('visibilité « secret » inconnue');
        $this->repository($this->tmp.'/b')->tracks();
    }

    public function testFicheDeCoursDeChapitre(): void
    {
        $filesystem = new Filesystem();
        foreach ([
            'pack.yaml' => "id: p\ntitle: P\ntracks: [t]",
            'tracks/t/track.yaml' => "id: t\ntitle: T\nenvironment: symfony-8\nchapters:\n  - {id: c1, title: C1, exercises: [e1]}\n  - {id: c2, title: C2, exercises: [e2]}",
            'tracks/t/chapters/c1/lesson.md' => <<<'MD'
                ## Ce que vous avez appris

                Voir [le routage](https://symfony.com/doc/current/routing.html#route-parameters) et <https://symfony.com/doc/current/controller.html>.
                Encore [le routage](https://symfony.com/doc/current/routing.html#route-parameters), et [une référence][doc].

                [doc]: https://symfony.com/doc/current/page_creation.html
                MD,
            'tracks/t/exercises/e1/exercise.yaml' => "id: e1\ntitle: E1\neditable: [a.php]\nobjectives: [{test: testA, label: A}]",
            'tracks/t/exercises/e1/instructions.md' => 'Consignes',
            'tracks/t/exercises/e2/exercise.yaml' => "id: e2\ntitle: E2\neditable: [a.php]\nobjectives: [{test: testA, label: A}]",
            'tracks/t/exercises/e2/instructions.md' => 'Consignes',
        ] as $path => $content) {
            $filesystem->dumpFile($this->tmp.'/p/'.$path, $content);
        }
        $repository = $this->repository($this->tmp);
        [$c1, $c2] = $repository->findTrack('t')->chapters;

        $this->assertTrue($c1->hasLesson());
        $this->assertStringStartsWith('## Ce que vous avez appris', (string) $c1->lesson);
        $this->assertSame([
            'https://symfony.com/doc/current/routing.html#route-parameters',
            'https://symfony.com/doc/current/controller.html',
            'https://symfony.com/doc/current/page_creation.html',
        ], $c1->lessonLinks(), 'Liens inline, autolinks et définitions de référence, sans doublon.');

        $this->assertFalse($c2->hasLesson(), 'Un chapitre sans lesson.md reste valide.');
        $this->assertNull($c2->lesson);
        $this->assertSame([], $c2->lessonLinks());

        $this->assertSame(
            [['t', 'c2']],
            array_map(static fn (array $pair) => [$pair[0]->id, $pair[1]->id], $repository->chaptersOf($repository->select('t/e2'))),
            'chaptersOf() remonte les chapitres des exercices sélectionnés.',
        );
    }

    public function testLePackDeDemoAUneFicheDeCours(): void
    {
        $chapter = $this->repository(self::ROOT.'/examples/packs')->findTrack('decouverte')->chapters[0];

        $this->assertTrue($chapter->hasLesson());
        $this->assertStringContainsString('Paramètre de route', (string) $chapter->lesson);
    }

    public function testRequetesDExemple(): void
    {
        $filesystem = new Filesystem();
        foreach ([
            'pack.yaml' => "id: p\ntitle: P\ntracks: [t]",
            'tracks/t/track.yaml' => "id: t\ntitle: T\nenvironment: symfony-8\nchapters:\n  - {id: c1, title: C1, exercises: [e1]}",
            'tracks/t/exercises/e1/exercise.yaml' => <<<'YAML'
                id: e1
                title: E1
                concepts: [Route, 404]
                editable: [a.php]
                objectives: [{test: testA, label: A}]
                docs:
                  - {title: Le routage, url: 'https://symfony.com/doc/current/routing.html'}
                requests:
                  - path: /api/plats
                  - title: Réserver
                    method: post
                    path: /api/reservations
                    headers: {Authorization: Bearer abc}
                    body: {nom: Gimli, jour: '{{ date:+7 }}'}
                YAML,
            'tracks/t/exercises/e1/instructions.md' => 'Consignes',
        ] as $path => $content) {
            $filesystem->dumpFile($this->tmp.'/p/'.$path, $content);
        }

        [$liste, $reserver] = $this->repository($this->tmp)->findExercise('t', 'e1')->requests;

        $this->assertSame(['GET /api/plats', 'GET', '/api/plats', [], null], [$liste->title, $liste->method, $liste->path, $liste->headers, $liste->body]);
        $this->assertSame(['Réserver', 'POST', ['Authorization' => 'Bearer abc']], [$reserver->title, $reserver->method, $reserver->headers]);
        $this->assertSame(['nom' => 'Gimli', 'jour' => '{{ date:+7 }}'], json_decode((string) $reserver->body, true), 'Un corps YAML devient du JSON.');

        $this->assertSame(['Route', '404'], $this->repository($this->tmp)->findExercise('t', 'e1')->concepts, 'Une étiquette « 404 » reste une chaîne.');
        $docs = $this->repository($this->tmp)->findExercise('t', 'e1')->docs;
        $this->assertSame([['Le routage', 'https://symfony.com/doc/current/routing.html']], array_map(static fn ($d) => [$d->title, $d->url], $docs));
    }

    public function testParcoursSuivant(): void
    {
        $repository = $this->repository(self::ROOT.'/examples/packs', __DIR__.'/../Fixtures/packs');

        $this->assertSame('suite', $repository->nextTrack($repository->findTrack('debut'))?->id);
        $this->assertNull($repository->nextTrack($repository->findTrack('suite')), 'Sans « next », pas de parcours conseillé.');
        $this->assertSame('symfony-pour-dev-php', $repository->findTrack('decouverte')->next);
        $this->assertNull($repository->nextTrack($repository->findTrack('decouverte')), 'Un parcours conseillé qui n\'est pas installé est ignoré.');
    }

    public function testUnMotifRendModifiablesLesFichiersQuUneCommandeVaCreer(): void
    {
        $exercice = new \App\Content\Exercise(
            id: 'e', trackId: 't', title: 'E', concepts: [], xp: 0, access: Access::Account, environment: 'symfony-8',
            base: null, open: 'src/A.php', preview: '/', editable: ['src/A.php', 'migrations/*.php'], readonly: [],
            objectives: [], hints: [], instructions: '', directory: '/tmp',
        );

        $this->assertTrue($exercice->isEditable('src/A.php'));
        $this->assertTrue($exercice->isEditable('migrations/Version20260915120000.php'), 'Un fichier généré par la console est couvert par le motif.');
        $this->assertFalse($exercice->isEditable('migrations/archives/Version1.php'), '* ne franchit pas un dossier.');
        $this->assertFalse($exercice->isEditable('src/B.php'));
        $this->assertSame(['src/A.php'], $exercice->editablePaths());
    }

    public function testSelectionnerParChapitre(): void
    {
        $repository = $this->repository(self::ROOT.'/examples/packs');
        $ids = static fn (array $exercices) => array_map(static fn ($e) => $e->id, $exercices);

        $this->assertSame(['01-bonjour', '02-bonjour-prenom'], $ids($repository->select(null, 'bonjour')));
        $this->assertSame(['01-bonjour'], $ids($repository->select('decouverte/01-bonjour', 'bonjour')));
        $this->assertSame([], $repository->select(null, 'chapitre-inconnu'));
        $this->assertContains('bonjour', $repository->chapterIds());
    }

    public function testOrdreDesParcours(): void
    {
        $filesystem = new Filesystem();
        $track = static fn (string $id, string $order = '') => "id: $id\ntitle: $id\nenvironment: symfony-8\n$order\nchapters:\n  - {id: c, title: C, exercises: []}";
        foreach ([
            // Chargés dans l'ordre a, b, c, d (dossiers de packs triés, puis liste « tracks »).
            'a/pack.yaml' => "id: a\ntitle: A\ntracks: [a1, a2]",
            'a/tracks/a1/track.yaml' => $track('a1'),
            'a/tracks/a2/track.yaml' => $track('a2', 'order: 20'),
            'b/pack.yaml' => "id: b\ntitle: B\ntracks: [b1, b2]",
            'b/tracks/b1/track.yaml' => $track('b1'),
            'b/tracks/b2/track.yaml' => $track('b2', 'order: 10'),
            'c/pack.yaml' => "id: c\ntitle: C\ntracks: [c1]",
            'c/tracks/c1/track.yaml' => $track('c1', 'order: 20'),
        ] as $path => $content) {
            $filesystem->dumpFile($this->tmp.'/'.$path, $content);
        }

        $this->assertSame(
            ['b2', 'a2', 'c1', 'a1', 'b1'],
            array_keys($this->repository($this->tmp)->tracks()),
            'Le plus petit rang d\'abord, l\'ordre de chargement à rang égal, les parcours sans rang à la fin.',
        );
    }

    public function testUnCheminPeutDesignerUnPackDirectement(): void
    {
        $this->assertArrayHasKey('demo', $this->repository(self::ROOT.'/examples/packs/demo')->packs());
    }

    public function testUnPackPeutExigerUneVersionDuMoteur(): void
    {
        $filesystem = new Filesystem();
        foreach ([
            'pack.yaml' => "id: p\ntitle: P\nmoteur: '>=0.1'\ntracks: [t]",
            'tracks/t/track.yaml' => "id: t\ntitle: T\nenvironment: symfony-8\nchapters:\n  - {id: c, title: C, exercises: []}",
        ] as $path => $content) {
            $filesystem->dumpFile($this->tmp.'/p/'.$path, $content);
        }

        $this->assertSame('>=0.1', $this->repository($this->tmp)->packs()['p']->engine);
        $this->assertNull($this->repository(self::ROOT.'/examples/packs')->packs()['demo']->engine, 'Sans la clé « moteur », un pack ne contraint rien.');
    }

    public function testChargeLesExercicesDePratique(): void
    {
        $repository = $this->repository(self::ROOT.'/examples/packs', __DIR__.'/../Fixtures/packs/pratique');

        $this->assertSame(['programme', 'exemple-map-request-header', 'en-preparation', 'nouveaute-recente', 'point-precis', 'cote-laravel'], array_keys($repository->practices()), 'Du plus récent au plus ancien, tous packs confondus.');

        $practice = $repository->findPractice('nouveaute-recente');
        $this->assertNotNull($practice);
        $this->assertNull($practice->exercise->trackId, 'Un exercice de Pratique n\'a pas de parcours.');
        $this->assertSame('pratique-test', $practice->packId);
        $this->assertSame('symfony', $practice->framework);
        $this->assertSame('8.1', $practice->version);
        $this->assertSame('https://github.com/symfony/symfony/pull/1', $practice->pullRequest);
        $this->assertSame('2026-09-10', $practice->published->format('Y-m-d'));
        $this->assertSame(Access::Account, $practice->exercise->access, 'La Pratique demande toujours un compte.');
        $this->assertSame(0, $practice->exercise->xp, 'La Pratique ne rapporte pas d\'XP.');
        $this->assertFalse($practice->isRestricted());
        $this->assertTrue($repository->findPractice('en-preparation')?->isRestricted());
        $this->assertSame('laravel', $repository->findPractice('cote-laravel')?->framework);
        $this->assertSame(['cote-laravel', 'en-preparation', 'nouveaute-recente', 'point-precis', 'programme'], $repository->packs()['pratique-test']->practiceIds);
        $this->assertTrue($repository->findPractice('programme')?->isScheduled(), 'Daté d\'un jour à venir.');
        $this->assertFalse($practice->isScheduled());
        $this->assertFalse($practice->isScheduled(new \DateTimeImmutable('2026-09-10')), 'Le jour même, il est publié.');
        $this->assertTrue($practice->isScheduled(new \DateTimeImmutable('2026-09-09')));

        $this->assertNull($repository->next($practice->exercise), 'Pas d\'exercice suivant hors parcours.');
        $this->assertNull($repository->chapterOf($practice->exercise));
        $this->assertFalse($repository->closesChapter($practice->exercise));
    }

    public function testChargeLesIntrosDeVersion(): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->tmp.'/p/pack.yaml', "id: p\ntitle: P");
        $filesystem->dumpFile($this->tmp.'/p/versions/symfony-8-2.md', "8.2 est la version des formulaires.\n");

        $intros = $this->repository($this->tmp)->versionIntros();

        $this->assertSame(['symfony-8-2'], array_keys($intros));
        $this->assertSame('8.2 est la version des formulaires.', $intros['symfony-8-2']['markdown']);
        $this->assertSame('p', $intros['symfony-8-2']['packId']);
        $this->assertFileExists($intros['symfony-8-2']['file']);
    }

    public function testSelectionnerLaPratique(): void
    {
        $repository = $this->repository(self::ROOT.'/examples/packs', __DIR__.'/../Fixtures/packs/pratique');
        $ids = static fn (array $exercises) => array_map(static fn ($e) => ($e->trackId ?? 'pratique').'/'.$e->id, $exercises);

        $this->assertSame(['pratique/programme', 'pratique/exemple-map-request-header', 'pratique/en-preparation', 'pratique/nouveaute-recente', 'pratique/point-precis', 'pratique/cote-laravel'], $ids($repository->select('pratique')));
        $this->assertSame(['pratique/point-precis'], $ids($repository->select('pratique/point-precis')));
        $this->assertCount(5, $repository->select('pratique-test'), 'Un pack comprend ses exercices de Pratique.');
        $this->assertSame(['decouverte/01-bonjour', 'decouverte/02-bonjour-prenom', 'pratique/exemple-map-request-header'], $ids($repository->select('demo')));
        $this->assertContains('pratique/point-precis', $ids($repository->select(null)));
        $this->assertSame(['decouverte/01-bonjour', 'decouverte/02-bonjour-prenom'], $ids($repository->select(null, 'bonjour')), 'Un chapitre exclut la Pratique.');
    }

    /**
     * @param array<string, string> $files
     */
    #[DataProvider('pratiquesInvalides')]
    public function testSignaleLesExercicesDePratiqueInvalides(array $files, string $message): void
    {
        $filesystem = new Filesystem();
        $files += [
            'p/pack.yaml' => "id: p\ntitle: P",
            'p/practice/x/exercise.yaml' => "id: x\ntitle: X\nenvironment: symfony-8\npublished: 2026-09-01\nsummary: S\neditable: [a.php]\nobjectives: [{test: testA, label: A}]",
            'p/practice/x/instructions.md' => 'Consignes',
        ];
        foreach ($files as $path => $content) {
            $filesystem->dumpFile($this->tmp.'/'.$path, $content);
        }

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage($message);
        $this->repository($this->tmp)->packs();
    }

    public static function pratiquesInvalides(): iterable
    {
        $exercice = static fn (string $extra) => ['p/practice/x/exercise.yaml' => "id: x\ntitle: X\nenvironment: symfony-8\npublished: 2026-09-01\nsummary: S\neditable: [a.php]\nobjectives: [{test: testA, label: A}]\n".$extra];

        yield 'accès libre' => [$exercice('access: free'), '« access » n\'a pas cours dans un exercice de Pratique'];
        yield 'base' => [$exercice('base: y'), '« base » n\'a pas cours'];
        yield 'XP' => [$exercice('xp: 50'), '« xp » n\'a pas cours'];
        yield 'sans environnement' => [
            ['p/practice/x/exercise.yaml' => "id: x\ntitle: X\npublished: 2026-09-01\nsummary: S\neditable: [a.php]\nobjectives: [{test: testA, label: A}]"],
            'clé « environment » manquante',
        ];
        yield 'sans date' => [
            ['p/practice/x/exercise.yaml' => "id: x\ntitle: X\nenvironment: symfony-8\nsummary: S\neditable: [a.php]\nobjectives: [{test: testA, label: A}]"],
            '« published » doit être une date',
        ];
        yield 'sans résumé' => [
            ['p/practice/x/exercise.yaml' => "id: x\ntitle: X\nenvironment: symfony-8\npublished: 2026-09-01\neditable: [a.php]\nobjectives: [{test: testA, label: A}]"],
            'clé « summary » manquante',
        ];
        yield 'version sans guillemets' => [$exercice('version: 8.10'), '« version » s\'écrit entre guillemets'];
        yield 'pull request qui n\'est pas une URL https' => [$exercice('pull_request: http://example.com/pr/1'), '« pull_request » doit être une URL https'];
        yield 'visibilité inconnue' => [$exercice('visibility: secret'), 'visibilité « secret » inconnue'];
        yield 'le même exercice dans deux packs' => [
            [
                'q/pack.yaml' => "id: q\ntitle: Q",
                'q/practice/x/exercise.yaml' => "id: x\ntitle: X\nenvironment: symfony-8\npublished: 2026-09-01\nsummary: S\neditable: [a.php]\nobjectives: [{test: testA, label: A}]",
                'q/practice/x/instructions.md' => 'Consignes',
            ],
            'Exercice de Pratique « x » présent deux fois',
        ];
        yield 'intro de version mal nommée' => [
            ['p/versions/Symfony 8.2.md' => 'Une intro.'],
            'le nom du fichier doit être l\'identifiant de la version dans l\'adresse',
        ];
        yield 'intro de version vide' => [
            ['p/versions/symfony-8-2.md' => "  \n"],
            'vide : une page sans intro écrite compose son texte toute seule',
        ];
        yield 'la même intro dans deux packs' => [
            [
                'p/versions/symfony-8-2.md' => 'Une intro.',
                'q/pack.yaml' => "id: q\ntitle: Q",
                'q/versions/symfony-8-2.md' => 'Une autre intro.',
            ],
            'Intro de version « symfony-8-2 » présente deux fois',
        ];
        yield 'un parcours nommé pratique' => [
            [
                'p/pack.yaml' => "id: p\ntitle: P\ntracks: [pratique]",
                'p/tracks/pratique/track.yaml' => "id: pratique\ntitle: T\nenvironment: symfony-8\nchapters:\n  - {id: c, title: C, exercises: []}",
            ],
            '« pratique » est réservé aux exercices de Pratique',
        ];
    }

    /**
     * @param array<string, string> $files
     */
    #[DataProvider('packsInvalides')]
    public function testSignaleLesPacksInvalides(array $files, string $message): void
    {
        $filesystem = new Filesystem();
        $files += [
            'pack.yaml' => "id: cassé\ntitle: Cassé\ntracks: [t]",
            'tracks/t/track.yaml' => "id: t\ntitle: T\nenvironment: symfony-8\nchapters:\n  - {id: c, title: C, exercises: [e1, e2]}",
            'tracks/t/exercises/e1/exercise.yaml' => "id: e1\ntitle: E1\neditable: [a.php]\nobjectives: [{test: testA, label: A}]",
            'tracks/t/exercises/e1/instructions.md' => 'Consignes',
            'tracks/t/exercises/e2/exercise.yaml' => "id: e2\ntitle: E2\neditable: [a.php]\nobjectives: [{test: testA, label: A}]",
            'tracks/t/exercises/e2/instructions.md' => 'Consignes',
        ];
        foreach ($files as $path => $content) {
            $filesystem->dumpFile($this->tmp.'/pack/'.$path, $content);
        }

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage($message);
        $this->repository($this->tmp)->packs();
    }

    public static function packsInvalides(): iterable
    {
        yield 'moteur trop ancien pour le pack' => [
            ['pack.yaml' => "id: cassé\ntitle: Cassé\nmoteur: '^99.0'\ntracks: [t]"],
            'demande un moteur ^99.0',
        ];
        yield 'contrainte de moteur illisible' => [
            ['pack.yaml' => "id: cassé\ntitle: Cassé\nmoteur: dernière\ntracks: [t]"],
            'contrainte « moteur: dernière » illisible',
        ];
        yield 'rang de parcours qui n\'est pas un entier' => [
            ['tracks/t/track.yaml' => "id: t\ntitle: T\nenvironment: symfony-8\norder: premier\nchapters:\n  - {id: c, title: C, exercises: [e1, e2]}"],
            '« order » doit être un nombre entier',
        ];
        yield 'environnement inconnu' => [
            ['tracks/t/track.yaml' => "id: t\ntitle: T\nenvironment: cobol-85\nchapters:\n  - {id: c, title: C, exercises: [e1]}"],
            'Environnement « cobol-85 » introuvable',
        ];
        yield 'base qui pointe vers un exercice suivant' => [
            ['tracks/t/exercises/e1/exercise.yaml' => "id: e1\ntitle: E1\nbase: e2\neditable: [a.php]\nobjectives: [{test: testA, label: A}]"],
            'la base « e2 » doit être un exercice précédent',
        ];
        yield 'id différent du dossier' => [
            ['tracks/t/exercises/e2/exercise.yaml' => "id: autre\ntitle: E2\neditable: [a.php]\nobjectives: [{test: testA, label: A}]"],
            'doit correspondre au nom du dossier',
        ];
        yield 'durée qui n\'est pas un nombre de minutes' => [
            ['tracks/t/exercises/e2/exercise.yaml' => "id: e2\ntitle: E2\nduration: 1h30\neditable: [a.php]\nobjectives: [{test: testA, label: A}]"],
            '« duration » est une durée estimée en minutes',
        ];
        yield 'sans objectif' => [
            ['tracks/t/exercises/e2/exercise.yaml' => "id: e2\ntitle: E2\neditable: [a.php]"],
            'au moins un objectif est requis',
        ];
        yield 'accès inconnu' => [
            ['tracks/t/exercises/e2/exercise.yaml' => "id: e2\ntitle: E2\naccess: vip\neditable: [a.php]\nobjectives: [{test: testA, label: A}]"],
            '« access » doit valoir free ou account',
        ];
        yield 'chapitre déclaré deux fois' => [
            ['tracks/t/track.yaml' => "id: t\ntitle: T\nenvironment: symfony-8\nchapters:\n  - {id: c, title: C, exercises: [e1]}\n  - {id: c, title: C bis, exercises: [e2]}"],
            'chapitre « c » déclaré deux fois',
        ];
        yield 'environnement de chapitre inconnu' => [
            ['tracks/t/track.yaml' => "id: t\ntitle: T\nenvironment: symfony-8\nchapters:\n  - {id: c, title: C, environment: cobol-85, exercises: [e1]}"],
            'Environnement « cobol-85 » introuvable',
        ];
        yield 'commande de setup invalide' => [
            ['tracks/t/exercises/e2/exercise.yaml' => "id: e2\ntitle: E2\neditable: [a.php]\nsetup: [[doctrine]]\nobjectives: [{test: testA, label: A}]"],
            'chaque commande de « setup » est une chaîne',
        ];
        yield 'requête d\'exemple sans chemin absolu' => [
            ['tracks/t/exercises/e2/exercise.yaml' => "id: e2\ntitle: E2\neditable: [a.php]\nrequests: [{path: api/plats}]\nobjectives: [{test: testA, label: A}]"],
            'doit commencer par « / »',
        ];
        yield 'requête d\'exemple avec une méthode inconnue' => [
            ['tracks/t/exercises/e2/exercise.yaml' => "id: e2\ntitle: E2\neditable: [a.php]\nrequests: [{method: BREW, path: /cafe}]\nobjectives: [{test: testA, label: A}]"],
            'méthode « BREW » inconnue',
        ];
        yield 'lien de documentation dangereux' => [
            ['tracks/t/exercises/e2/exercise.yaml' => "id: e2\ntitle: E2\neditable: [a.php]\ndocs: [{title: Piège, url: 'javascript:alert(1)'}]\nobjectives: [{test: testA, label: A}]"],
            'doit être une URL http(s)',
        ];
        yield 'fichier ouvert désigné par un motif' => [
            ['tracks/t/exercises/e2/exercise.yaml' => "id: e2\ntitle: E2\nopen: 'migrations/*.php'\neditable: ['migrations/*.php']\nreadonly: [a.php]\nobjectives: [{test: testA, label: A}]"],
            'doit désigner un fichier, pas un motif',
        ];
        yield 'fichier éditable par un motif et en lecture seule' => [
            ['tracks/t/exercises/e2/exercise.yaml' => "id: e2\ntitle: E2\neditable: ['migrations/*.php']\nreadonly: [a.php, migrations/Version1.php]\nobjectives: [{test: testA, label: A}]"],
            '« migrations/Version1.php » est à la fois éditable (migrations/*.php) et en lecture seule',
        ];
        yield 'fichier ouvert hors des motifs éditables' => [
            ['tracks/t/exercises/e2/exercise.yaml' => "id: e2\ntitle: E2\nopen: src/Controller/A.php\neditable: ['src/Entity/*.php']\nobjectives: [{test: testA, label: A}]"],
            '« open » (src/Controller/A.php) doit faire partie',
        ];
        yield 'fichier ouvert non éditable' => [
            ['tracks/t/exercises/e2/exercise.yaml' => "id: e2\ntitle: E2\nopen: b.php\neditable: [a.php]\nobjectives: [{test: testA, label: A}]"],
            '« open » (b.php) doit faire partie',
        ];
    }
}
