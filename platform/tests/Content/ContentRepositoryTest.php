<?php

namespace App\Tests\Content;

use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Tests\TrackImageTrait;
use App\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ContentRepositoryTest extends TestCase
{
    use TrackImageTrait;

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

    public function testLeChapitreDUnExerciceDependDeSonParcours(): void
    {
        $exercise = static fn (string $id) => [
            "exercises/$id/exercise.yaml" => "id: $id\ntitle: $id\neditable: [a.php]\nobjectives: [{test: testA, label: A}]",
            "exercises/$id/instructions.md" => 'Consignes',
        ];
        $files = ['pack.yaml' => "id: p\ntitle: P\ntracks: [t1, t2]"];
        // Même identifiant d'exercice dans deux parcours, dans des chapitres différents.
        foreach (['t1' => 'debut', 't2' => 'fin'] as $track => $chapter) {
            $files["tracks/$track/track.yaml"] = "id: $track\ntitle: T\nenvironment: symfony-8\nchapters:\n  - {id: intro-$track, title: I, exercises: [e0]}\n  - {id: $chapter, title: C, exercises: [e1]}";
            foreach ([...$exercise('e0'), ...$exercise('e1')] as $path => $content) {
                $files["tracks/$track/$path"] = $content;
            }
        }
        foreach ($files as $path => $content) {
            (new Filesystem())->dumpFile($this->tmp.'/p/'.$path, $content);
        }
        $repository = $this->repository($this->tmp);

        $this->assertSame('debut', $repository->chapterOf($repository->findExercise('t1', 'e1') ?? $this->fail())?->id);
        $this->assertSame('fin', $repository->chapterOf($repository->findExercise('t2', 'e1') ?? $this->fail())?->id);
        $this->assertSame('intro-t2', $repository->chapterOf($repository->findExercise('t2', 'e0') ?? $this->fail())?->id);
        $this->assertTrue($repository->closesChapter($repository->findExercise('t1', 'e0') ?? $this->fail()));

        // Après une écriture dans le pack (l'atelier appelle forget()), l'index est reconstruit.
        (new Filesystem())->dumpFile($this->tmp.'/p/tracks/t1/track.yaml', "id: t1\ntitle: T\nenvironment: symfony-8\nchapters:\n  - {id: tout, title: Tout, exercises: [e0, e1]}");
        $repository->forget();
        $this->assertSame('tout', $repository->chapterOf($repository->findExercise('t1', 'e1') ?? $this->fail())?->id);
    }

    public function testLaCleAccessEstDepreciee(): void
    {
        $repository = $this->repository(self::ROOT.'/examples/packs', __DIR__.'/../Fixtures/packs/enchainement');

        $this->assertNotNull($repository->findExercise('debut', 'e1'), 'Encore acceptée, pour ne casser aucun pack.');
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
            id: 'e', trackId: 't', title: 'E', concepts: [], xp: 0, environment: 'symfony-8',
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

        $this->assertSame(['01-bonjour', '01-bonjour', '02-bonjour-prenom', '02-bonjour-prenom'], $ids($repository->select(null, 'bonjour')), 'Avec leur traduction.');
        $this->assertSame(['01-bonjour', '01-bonjour'], $ids($repository->select('decouverte/01-bonjour', 'bonjour')));
        $this->assertSame([], $repository->select(null, 'chapitre-inconnu'));
        $this->assertContains('bonjour', $repository->chapterIds());
    }

    public function testLesExercicesDUnChapitre(): void
    {
        $repository = $this->repository(self::ROOT.'/examples/packs');
        $track = $repository->findTrack('decouverte');
        $this->assertNotNull($track);
        $ids = static fn (array $exercices) => array_map(static fn ($e) => $e->id, $exercices);

        $this->assertSame(['01-bonjour', '02-bonjour-prenom'], $ids($repository->exercisesOfChapter($track, $repository->findChapter($track, 'bonjour'))));
        $this->assertSame(['01-bonjour', '02-bonjour-prenom'], $ids($repository->exercisesOfChapter($track)));
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
        $this->assertSame(0, $practice->exercise->xp, 'La Pratique ne rapporte pas d\'XP.');
        $this->assertFalse($practice->isRestricted());
        $this->assertTrue($repository->findPractice('en-preparation')?->isRestricted());
        $this->assertSame('laravel', $repository->findPractice('cote-laravel')?->framework);
        $this->assertSame(['cote-laravel', 'en-preparation', 'nouveaute-recente', 'point-precis', 'programme'], $repository->packs()['pratique-test']->practiceIds);
        $this->assertTrue($repository->findPractice('programme')?->isScheduled(new \DateTimeImmutable('2026-09-27')), 'Daté d\'un jour à venir.');
        $this->assertFalse($practice->isScheduled(new \DateTimeImmutable('2026-09-27')));
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

    public function testChargeLesArticles(): void
    {
        $repository = $this->repository(__DIR__.'/../Fixtures/packs/pratique');

        $this->assertSame(
            ['article-programme', 'symfony-dans-le-navigateur', 'les-tests-en-direct', 'article-en-preparation'],
            array_keys($repository->articles()),
            'Du plus récent au plus ancien, parus ou non.',
        );
        $article = $repository->findArticle('symfony-dans-le-navigateur');
        $this->assertNotNull($article);
        $this->assertSame('pratique-test', $article->packId);
        $this->assertSame('Symfony dans le navigateur, sans serveur', $article->title);
        $this->assertSame('2026-09-20', $article->published->format('Y-m-d'));
        $this->assertSame('2026-09-25', $article->modified());
        $this->assertStringStartsWith('# Symfony dans le navigateur', $article->markdown, 'Le Markdown commence après l\'en-tête.');
        $this->assertSame(1, $article->readingMinutes());
        $this->assertTrue($repository->findArticle('article-en-preparation')?->isRestricted());
        $this->assertTrue($repository->findArticle('article-programme')?->isScheduled(new \DateTimeImmutable('2026-09-30')));
        $this->assertSame('2026-09-05', $repository->findArticle('les-tests-en-direct')?->modified(), 'Sans révision, la date de parution.');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function articlesRefuses(): iterable
    {
        $header = "title: T\ndescription: D\npublished: 2026-10-06";
        yield 'nom de fichier' => ['Mon_Article', "---\n$header\n---\nTexte", 'le nom du fichier est son adresse'];
        yield 'sans en-tête' => ['a', 'Texte seul', 'entre deux lignes « --- »'];
        yield 'clé inconnue' => ['a', "---\n$header\nauteur: A\n---\nTexte", 'clé « auteur » inconnue'];
        yield 'titre manquant' => ['a', "---\ndescription: D\npublished: 2026-10-06\n---\nTexte", 'clé « title » manquante'];
        yield 'date invalide' => ['a', "---\ntitle: T\ndescription: D\npublished: bientôt\n---\nTexte", '« published » doit être une date'];
        yield 'révision antérieure' => ['a', "---\n$header\nupdated: 2026-10-01\n---\nTexte", '« updated » précède « published »'];
        yield 'sans texte' => ['a', "---\n$header\n---\n\n", 'article sans texte'];
        yield 'description en HTML' => ['a', "---\ntitle: T\ndescription: <b>D</b>\npublished: 2026-10-06\n---\nTexte", 'sans balise HTML'];
        yield 'visibilité' => ['a', "---\n$header\nvisibility: secret\n---\nTexte", 'visibilité « secret » inconnue'];
    }

    #[DataProvider('articlesRefuses')]
    public function testUnArticleMalFormeEstRefuse(string $name, string $content, string $message): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->tmp.'/p/pack.yaml', "id: p\ntitle: P");
        $filesystem->dumpFile($this->tmp.'/p/articles/'.$name.'.md', $content);

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage($message);
        $this->repository($this->tmp)->articles();
    }

    public function testUnArticleNExisteQuUneFois(): void
    {
        $filesystem = new Filesystem();
        foreach (['p1', 'p2'] as $pack) {
            $filesystem->dumpFile("{$this->tmp}/{$pack}/pack.yaml", "id: {$pack}\ntitle: P");
            $filesystem->dumpFile("{$this->tmp}/{$pack}/articles/a.md", "---\ntitle: T\ndescription: D\npublished: 2026-10-06\n---\nTexte");
        }

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('Article « a » présent deux fois (packs p1 et p2)');
        $this->repository($this->tmp)->articles();
    }

    public function testChargeLesTraductionsDesArticles(): void
    {
        $repository = $this->repository(__DIR__.'/../Fixtures/packs/pratique');

        $this->assertSame(['a-scheduled-article', 'symfony-in-the-browser'], array_keys($repository->articles('en')));
        $this->assertNull($repository->findArticle('symfony-in-the-browser'), 'Une traduction n\'est pas un article français.');
        $translation = $repository->findArticle('symfony-in-the-browser', 'en');
        $this->assertNotNull($translation);
        $this->assertSame('en', $translation->locale);
        $this->assertSame('symfony-dans-le-navigateur', $translation->translationOf);
        $this->assertSame('Symfony in the browser, without a server', $translation->title);

        $original = $repository->findArticle('symfony-dans-le-navigateur');
        $this->assertNotNull($original);
        $this->assertSame('fr', $original->locale);
        $this->assertSame(['en' => $translation], $repository->translationsOf($original));
        $this->assertSame(['fr' => $original], $repository->translationsOf($translation));
        $this->assertSame([], $repository->translationsOf($repository->findArticle('les-tests-en-direct') ?? $original), 'Un article sans traduction.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function traductionsRefusees(): iterable
    {
        $header = "title: T\ndescription: D\npublished: 2026-10-06";
        yield 'sans translation_of' => ["---\n$header\n---\nText", 'clé « translation_of » manquante'];
        yield 'article inconnu' => ["---\n$header\ntranslation_of: inconnu\n---\nText", '« translation_of » nomme l\'article « inconnu », introuvable'];
    }

    #[DataProvider('traductionsRefusees')]
    public function testUneTraductionMalFormeeEstRefusee(string $content, string $message): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->tmp.'/p/pack.yaml', "id: p\ntitle: P");
        $filesystem->dumpFile($this->tmp.'/p/articles/a.md', "---\ntitle: T\ndescription: D\npublished: 2026-10-06\n---\nTexte");
        $filesystem->dumpFile($this->tmp.'/p/articles/en/b.md', $content);

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage($message);
        $this->repository($this->tmp)->articles();
    }

    public function testUnArticleFrancaisNeNommePasDOriginal(): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->tmp.'/p/pack.yaml', "id: p\ntitle: P");
        $filesystem->dumpFile($this->tmp.'/p/articles/a.md', "---\ntitle: T\ndescription: D\npublished: 2026-10-06\ntranslation_of: b\n---\nTexte");

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('clé « translation_of » inconnue');
        $this->repository($this->tmp)->articles();
    }

    public function testUnArticleNAQuUneTraductionParLangue(): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->tmp.'/p/pack.yaml', "id: p\ntitle: P");
        $filesystem->dumpFile($this->tmp.'/p/articles/a.md', "---\ntitle: T\ndescription: D\npublished: 2026-10-06\n---\nTexte");
        foreach (['b', 'c'] as $slug) {
            $filesystem->dumpFile($this->tmp."/p/articles/en/{$slug}.md", "---\ntitle: T\ndescription: D\npublished: 2026-10-06\ntranslation_of: a\n---\nText");
        }

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('L\'article « a » a deux traductions');
        $this->repository($this->tmp)->articles();
    }

    public function testChargeLesThemesDeNotions(): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->tmp.'/p/pack.yaml', "id: p\ntitle: P");
        $filesystem->dumpFile($this->tmp.'/p/notions/themes.yaml', <<<'YAML'
            symfony:
              - title: Doctrine & base de données
                description: Modéliser, migrer et interroger.
                notions: [Migrations, Requêtes N+1]
              - title: Sécurité
                notions: [Voter]
            laravel:
              - title: Eloquent
                notions: [Relations]
            YAML);

        $themes = $this->repository($this->tmp)->conceptThemes();

        $this->assertSame(['symfony', 'laravel'], array_keys($themes));
        $this->assertSame('doctrine-base-de-donnees', $themes['symfony'][0]->id);
        $this->assertSame('Doctrine & base de données', $themes['symfony'][0]->title);
        $this->assertSame('Modéliser, migrer et interroger.', $themes['symfony'][0]->description);
        $this->assertSame(['Migrations', 'Requêtes N+1'], $themes['symfony'][0]->concepts);
        $this->assertSame('', $themes['symfony'][1]->description, 'La description est facultative.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function themesDeNotionsRefuses(): iterable
    {
        yield 'framework inconnu' => ["cobol:\n  - {title: T, notions: [N]}", 'framework « cobol » inconnu'];
        yield 'pas une liste' => ["symfony: Fondations", '« symfony » est une liste de thèmes'];
        yield 'clé inconnue' => ["symfony:\n  - {title: T, notions: [N], couleur: rouge}", 'clé « couleur » inconnue'];
        yield 'sans titre' => ["symfony:\n  - {notions: [N]}", 'clé « title » manquante'];
        yield 'sans notions' => ["symfony:\n  - {title: T}", '« notions » est la liste des notions du thème'];
        yield 'notion qui n\'est pas un libellé' => ["symfony:\n  - {title: T, notions: [{nom: N}]}", 'une notion est un libellé'];
        yield 'notion rangée deux fois' => ["symfony:\n  - {title: A, notions: [Voter]}\n  - {title: B, notions: [voter]}", 'la notion « voter » est déjà rangée dans « A »'];
        yield 'thème déclaré deux fois' => ["symfony:\n  - {title: A, notions: [N]}\n  - {title: A, notions: [M]}", 'thème « A » déclaré deux fois'];
    }

    #[DataProvider('themesDeNotionsRefuses')]
    public function testDesThemesDeNotionsMalFormesSontRefuses(string $yaml, string $message): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->tmp.'/p/pack.yaml', "id: p\ntitle: P");
        $filesystem->dumpFile($this->tmp.'/p/notions/themes.yaml', $yaml);

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage($message);
        $this->repository($this->tmp)->conceptThemes();
    }

    public function testUnSeulPackRangeLesNotionsDUnFramework(): void
    {
        $filesystem = new Filesystem();
        foreach (['p1', 'p2'] as $pack) {
            $filesystem->dumpFile("{$this->tmp}/{$pack}/pack.yaml", "id: {$pack}\ntitle: P");
            $filesystem->dumpFile("{$this->tmp}/{$pack}/notions/themes.yaml", "symfony:\n  - {title: T, notions: [N]}");
        }

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('les notions Symfony sont déjà rangées par le pack « p1 »');
        $this->repository($this->tmp)->conceptThemes();
    }

    public function testSelectionnerLaPratique(): void
    {
        $repository = $this->repository(self::ROOT.'/examples/packs', __DIR__.'/../Fixtures/packs/pratique');
        $ids = static fn (array $exercises) => array_map(static fn ($e) => ($e->trackId ?? 'pratique').'/'.$e->id.('fr' === $e->locale ? '' : ' ['.$e->locale.']'), $exercises);

        $this->assertSame(['pratique/programme', 'pratique/exemple-map-request-header', 'pratique/exemple-map-request-header [en]', 'pratique/en-preparation', 'pratique/nouveaute-recente', 'pratique/point-precis', 'pratique/cote-laravel'], $ids($repository->select('pratique')));
        $this->assertSame(['pratique/point-precis'], $ids($repository->select('pratique/point-precis')));
        $this->assertCount(5, $repository->select('pratique-test'), 'Un pack comprend ses exercices de Pratique.');
        $this->assertSame(['decouverte/01-bonjour', 'decouverte/01-bonjour [en]', 'decouverte/02-bonjour-prenom', 'decouverte/02-bonjour-prenom [en]', 'pratique/exemple-map-request-header', 'pratique/exemple-map-request-header [en]'], $ids($repository->select('demo')), 'Chaque traduction se vérifie aussi.');
        $this->assertContains('pratique/point-precis', $ids($repository->select(null)));
        $this->assertSame(['decouverte/01-bonjour', 'decouverte/01-bonjour [en]', 'decouverte/02-bonjour-prenom', 'decouverte/02-bonjour-prenom [en]'], $ids($repository->select(null, 'bonjour')), 'Un chapitre exclut la Pratique.');
    }

    /** @param array<string, string> $extra */
    private function trackWithImage(string $image, array $extra = []): void
    {
        $filesystem = new Filesystem();
        $files = $extra + [
            'p/pack.yaml' => "id: p\ntitle: P\ntracks: [t]",
            'p/tracks/t/track.yaml' => "id: t\ntitle: T\nenvironment: symfony-8\nimage: {$image}\nchapters:\n  - {id: c, title: C, exercises: []}",
        ];
        foreach ($files as $path => $content) {
            $filesystem->dumpFile($this->tmp.'/'.$path, $content);
        }
    }

    /** @param array<string, string> $files */
    #[DataProvider('anciensIdsInvalides')]
    public function testUnAncienIdentifiantNeMeneQuAUnSeulEndroit(array $files, string $message): void
    {
        $filesystem = new Filesystem();
        foreach ($files + ['p/pack.yaml' => "id: p\ntitle: P\ntracks: [a, b]"] as $path => $content) {
            $filesystem->dumpFile($this->tmp.'/'.$path, $content);
        }

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage($message);
        $this->repository($this->tmp)->tracks();
    }

    public function testUnNiveauInconnuOuUneDescriptionEnHtmlSontSignales(): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->tmp.'/p/pack.yaml', "id: p\ntitle: P\ntracks: [t]");
        $filesystem->dumpFile($this->tmp.'/p/tracks/t/track.yaml', "id: t\ntitle: T\nenvironment: symfony-8\nlevel: expert\nchapters:\n  - {id: c, title: C, exercises: []}");
        try {
            $this->repository($this->tmp)->tracks();
            $this->fail('Niveau inconnu accepté.');
        } catch (ContentException $e) {
            $this->assertStringContainsString('niveau « expert » inconnu (beginner, intermediate, advanced)', $e->getMessage());
        }

        $filesystem->dumpFile($this->tmp.'/p/tracks/t/track.yaml', "id: t\ntitle: T\nenvironment: symfony-8\nchapters:\n  - {id: c, title: C, description: '<b>Gras</b>', exercises: []}");
        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('chapitre « c », « description » est un texte, sans balise HTML');
        $this->repository($this->tmp)->tracks();
    }

    public static function anciensIdsInvalides(): iterable
    {
        $track = static fn (string $id, string $former) => "id: {$id}\ntitle: T\nenvironment: symfony-8\nformer_ids: {$former}\nchapters:\n  - {id: c, title: C, exercises: []}";
        yield 'ancien id d\'un parcours actuel' => [['p/tracks/a/track.yaml' => $track('a', '[b]'), 'p/tracks/b/track.yaml' => $track('b', '[]')], 'l\'ancien identifiant « b » est celui d\'un autre, actuel'];
        yield 'deux fois le même ancien id' => [['p/tracks/a/track.yaml' => $track('a', '[x]'), 'p/tracks/b/track.yaml' => $track('b', '[x]')], 'déclarent tous deux l\'ancien identifiant « x »'];
        yield 'pas une liste' => [['p/tracks/a/track.yaml' => $track('a', 'ancien'), 'p/tracks/b/track.yaml' => $track('b', '[]')], '« former_ids » est une liste'];
    }

    public function testUnParcoursDeclareSonImageDePartage(): void
    {
        $this->trackWithImage('partage.png', ['p/tracks/t/partage.png' => self::pngHeader(1200, 630)]);

        $this->assertSame($this->tmp.'/p/tracks/t/partage.png', $this->repository($this->tmp)->findTrack('t')?->image);
    }

    #[DataProvider('imagesInvalides')]
    public function testUneImageDePartageInvalideEstSignalee(string $image, ?string $content, string $message): void
    {
        $this->trackWithImage($image, null === $content ? [] : ['p/tracks/t/'.$image => $content]);

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage($message);
        $this->repository($this->tmp)->tracks();
    }

    public static function imagesInvalides(): iterable
    {
        yield 'introuvable' => ['partage.png', null, 'image « partage.png » introuvable'];
        yield 'hors du dossier' => ['../../secret.png', null, 'chemin d\'un fichier du dossier du parcours'];
        yield 'pas une image' => ['partage.png', 'bonjour', 'PNG, JPEG ou WebP attendu'];
        yield 'trop petite' => ['partage.png', self::pngHeader(600, 315), 'en 600 × 315 : il faut au moins 1200 × 630'];
        yield 'carrée' => ['partage.png', self::pngHeader(1200, 1200), 'au format 1,91:1'];
    }

    public function testChargeLesTraductionsDesExercicesDePratique(): void
    {
        $repository = $this->repository(self::ROOT.'/examples/packs', __DIR__.'/../Fixtures/packs/pratique');

        $this->assertSame(['exemple-map-request-header'], array_keys($repository->practices('en')), 'En anglais, seuls les exercices traduits.');
        $this->assertSame(['fr', 'en'], $repository->practiceLocales('exemple-map-request-header'));
        $this->assertSame(['fr'], $repository->practiceLocales('point-precis'));
        $this->assertNull($repository->findPractice('point-precis', 'en'));

        $french = $repository->findPractice('exemple-map-request-header');
        $english = $repository->findPractice('exemple-map-request-header', 'en');
        $this->assertNotNull($french);
        $this->assertNotNull($english);
        $this->assertSame('fr', $french->locale());
        $this->assertSame('en', $english->locale());
        $this->assertSame('Read a header with #[MapRequestHeader]', $english->exercise->title);
        $this->assertStringStartsWith('An HTTP header arrives', $english->summary);
        $this->assertStringStartsWith('# Read a header', $english->exercise->instructions);
        $this->assertSame(['testTheRequestedVersionIsRead', 'testARequestWithoutTheHeaderIsRejected', 'testTheControllerUsesMapRequestHeader'], array_map(static fn ($o) => $o->test, $english->exercise->objectives));
        $this->assertSame('With the header', $english->exercise->requests[0]->title);
        $this->assertSame(['Controller', 'HttpKernel'], $english->exercise->concepts);
        // Ce qui ne se traduit pas vient de l'exercice français.
        $this->assertSame($french->published, $english->published);
        $this->assertSame($french->version, $english->version);
        $this->assertSame($french->exercise->environment, $english->exercise->environment);
        $this->assertSame($french->exercise->editable, $english->exercise->editable);
        $this->assertFalse($english->outdated, 'La traduction suit la dernière version de l\'exercice français.');

        // Les fichiers traduits remplacent ceux de l'exercice français, chemin par chemin.
        $this->assertStringContainsString('Missing X-Api-Version header.', $repository->startingFiles($english->exercise)['src/Controller/VersionController.php']);
        $this->assertStringContainsString('En-tête X-Api-Version manquant.', $repository->startingFiles($french->exercise)['src/Controller/VersionController.php']);
        $this->assertStringContainsString('Requested version: ', $repository->solutionFiles($english->exercise)['src/Controller/VersionController.php']);
        $this->assertSame(['tests/Pratique/VersionTest.php'], array_keys($repository->testFiles($english->exercise)));
        $this->assertStringContainsString('testTheRequestedVersionIsRead', $repository->testFiles($english->exercise)['tests/Pratique/VersionTest.php']);

        // content:check vérifie chaque langue.
        $this->assertSame(['fr', 'en'], array_map(static fn ($e) => $e->locale, $repository->select('pratique/exemple-map-request-header')));
    }

    public function testUneTraductionEnRetardEstSignalee(): void
    {
        $this->traduction(['p/practice/x/i18n/en/exercise.yaml' => "source: autrefois\ntitle: X en\nsummary: S en\nobjectives: [{test: testA, label: A en}]"]);
        $this->assertTrue($this->repository($this->tmp)->findPractice('x', 'en')?->outdated, 'L\'empreinte ne correspond plus à l\'exercice français.');

        $this->traduction(['p/practice/x/i18n/en/exercise.yaml' => "title: X en\nsummary: S en\nobjectives: [{test: testA, label: A en}]"]);
        $this->assertTrue($this->repository($this->tmp)->findPractice('x', 'en')?->outdated, 'Sans empreinte, rien ne dit ce qu\'elle suit.');

        $source = \App\Content\ExerciseReader::fingerprint($this->tmp.'/p/practice/x');
        $this->traduction(['p/practice/x/i18n/en/exercise.yaml' => "source: $source\ntitle: X en\nsummary: S en\nobjectives: [{test: testA, label: A en}]"]);
        $this->assertFalse($this->repository($this->tmp)->findPractice('x', 'en')?->outdated);
    }

    public function testUneTraductionDeclareSesPropresMutants(): void
    {
        $this->traduction(self::avecMutant("mutants: [{id: double, label: Saved twice, changes: [{file: a.php, search: '\$order', replace: '\$order2'}]}]"));

        $english = $this->repository($this->tmp)->findPractice('x', 'en');
        $this->assertNotNull($english);
        $this->assertSame('Saved twice', $english->exercise->mutants[0]->label);
        $this->assertSame('$order', $english->exercise->mutants[0]->changes[0]['search']);
        $this->assertSame('mutant:double', $english->exercise->objectives[0]->test);
    }

    /**
     * @param array<string, string> $files
     */
    #[DataProvider('traductionsInvalides')]
    public function testUneTraductionDExerciceMalFormeeEstRefusee(array $files, string $message): void
    {
        $this->traduction($files);

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage($message);
        $this->repository($this->tmp)->packs();
    }

    public static function traductionsInvalides(): iterable
    {
        $yaml = "title: X en\nsummary: S en\nobjectives: [{test: testA, label: A en}]\n";

        yield 'une clé qui ne se traduit pas' => [['p/practice/x/i18n/en/exercise.yaml' => $yaml.'environment: laravel-13'], '« environment » ne se traduit pas'];
        yield 'un texte oublié' => [['p/practice/x/i18n/en/exercise.yaml' => "title: X en\nsummary: S en"], '« objectives » manque'];
        yield 'sans consignes' => [['p/practice/x/i18n/en/instructions.md' => null], 'i18n/en/instructions.md manquant'];
        yield 'une langue inconnue' => [['p/practice/x/i18n/de/exercise.yaml' => $yaml], '« de » n\'est pas une langue de traduction'];
        yield 'un fichier qui ne remplace rien' => [['p/practice/x/i18n/en/starter/b.php' => '<?php'], 'i18n/en/starter/b.php ne remplace aucun fichier'];
        yield 'un fichier inattendu' => [['p/practice/x/i18n/en/notes.md' => 'Notes'], '« notes.md » n\'a pas cours dans une traduction'];
        yield 'des mutants oubliés' => [self::avecMutant(''), '« mutants » manque'];
        yield 'des mutants renommés' => [self::avecMutant("mutants: [{id: twice, label: Saved twice, changes: [{file: a.php, search: a, replace: b}]}]"), 'gardent les ids de l\'exercice français (double)'];
        yield 'un objectif sans test' => [['p/practice/x/i18n/en/exercise.yaml' => "title: X en\nsummary: S en\nobjectives: [{label: A en}]"], 'clé « test » manquante'];
    }

    public function testChargeLaTraductionDUnParcours(): void
    {
        $repository = $this->repository(self::ROOT.'/examples/packs');

        $this->assertSame(['fr', 'en'], $repository->trackLocales('decouverte'));
        $this->assertSame(['decouverte'], array_keys($repository->tracks('en')));
        $french = $repository->findTrack('decouverte');
        $english = $repository->findTrack('decouverte', 'en');
        $this->assertNotNull($french);
        $this->assertNotNull($english);
        $this->assertSame('en', $english->locale);
        $this->assertSame('Discovery', $english->title);
        $this->assertSame('Hello Symfony', $english->chapters[0]->title);
        $this->assertStringStartsWith('## What you learned', (string) $english->chapters[0]->lesson);
        $this->assertSame($french->chapters[0]->exerciseIds, $english->chapters[0]->exerciseIds);
        $this->assertFalse($repository->isOutdated($english));

        [$first, $second] = $repository->exercisesOf($english);
        $this->assertSame(['en', 'en'], [$first->locale, $second->locale]);
        $this->assertSame('Hello to you', $second->title);
        $this->assertSame('decouverte', $second->trackId);
        $this->assertFalse($repository->isOutdated($second));
        $this->assertSame($second, $repository->next($first), 'On reste dans la langue du parcours.');
        $this->assertSame('Hello Symfony', $repository->chapterOf($second)?->title);
        $this->assertSame([[$english, $english->chapters[0]]], $repository->chaptersOf([$first]));
        // Un exercice qui part du précédent part de sa solution traduite.
        $this->assertStringContainsString("#[Route('/hello', name: 'app_hello')]", $repository->startingFiles($second)['src/Controller/BonjourController.php']);
        $this->assertStringContainsString("#[Route('/bonjour', name: 'app_bonjour')]", $repository->startingFiles($repository->exercisesOf($french)[1])['src/Controller/BonjourController.php']);

        // content:check vérifie chaque langue.
        $this->assertSame(['fr', 'en'], array_map(static fn ($e) => $e->locale, $repository->select('decouverte/01-bonjour')));
    }

    public function testUnParcoursEnRetardEstSignale(): void
    {
        $this->parcoursTraduit(['p/tracks/t/i18n/en/track.yaml' => "source: 'autrefois'\ntitle: T en\nchapters: {c: {title: C en}}"]);
        $repository = $this->repository($this->tmp);
        $english = $repository->findTrack('t', 'en');
        $this->assertNotNull($english);
        $this->assertTrue($repository->isOutdated($english));
        $this->assertTrue($repository->isOutdated($repository->exercisesOf($english)[0]), 'Sans empreinte, rien ne dit ce que la traduction suit.');

        $french = $repository->findTrack('t');
        $this->assertNotNull($french);
        $this->parcoursTraduit([
            'p/tracks/t/i18n/en/track.yaml' => sprintf("source: '%s'\ntitle: T en\nchapters: {c: {title: C en}}", \App\Content\PackReader::trackFingerprint($french)),
            'p/tracks/t/exercises/x/i18n/en/exercise.yaml' => sprintf("source: '%s'\ntitle: X en\nobjectives: [{test: testA, label: A en}]", \App\Content\ExerciseReader::fingerprint($this->tmp.'/p/tracks/t/exercises/x')),
        ]);
        $repository = $this->repository($this->tmp);
        $english = $repository->findTrack('t', 'en');
        $this->assertNotNull($english);
        $this->assertFalse($repository->isOutdated($english));
        $this->assertFalse($repository->isOutdated($repository->exercisesOf($english)[0]));
    }

    public function testUnParcoursNeParaitPasDansUneLangueAvantSaTraduction(): void
    {
        $this->parcoursTraduit(['p/tracks/t/i18n/en/track.yaml' => null]);
        $repository = $this->repository($this->tmp);

        $this->assertNull($repository->findTrack('t', 'en'));
        $this->assertSame(['fr'], $repository->trackLocales('t'));
        $this->assertSame(['fr', 'en'], array_map(static fn ($e) => $e->locale, $repository->select('t')), 'Ses exercices traduits se vérifient déjà.');
    }

    /**
     * @param array<string, string|null> $files
     */
    #[DataProvider('parcoursTraduitsInvalides')]
    public function testUneTraductionDeParcoursMalFormeeEstRefusee(array $files, string $message): void
    {
        $this->parcoursTraduit($files);

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage($message);
        $this->repository($this->tmp)->packs();
    }

    public static function parcoursTraduitsInvalides(): iterable
    {
        yield 'une clé qui ne se traduit pas' => [['p/tracks/t/i18n/en/track.yaml' => "title: T en\nenvironment: laravel-13\nchapters: {c: {title: C en}}"], '« environment » ne se traduit pas'];
        yield 'un chapitre sans titre' => [['p/tracks/t/i18n/en/track.yaml' => "title: T en\nchapters: {c: {description: D}}"], 'clé « title » manquante'];
        yield 'des chapitres en liste' => [['p/tracks/t/i18n/en/track.yaml' => "title: T en\nchapters: [C en]"], '« chapters » associe à l\'id de chaque chapitre'];
        yield 'sans track.yaml' => [['p/tracks/t/i18n/en/track.yaml' => null, 'p/tracks/t/i18n/en/chapters/c/lesson.md' => 'Lesson'], 'track.yaml manquant'];
        yield 'une langue inconnue' => [['p/tracks/t/i18n/de/track.yaml' => 'title: T de'], '« de » n\'est pas une langue de traduction'];
    }

    /**
     * @param array<string, string|null> $files
     */
    #[DataProvider('parcoursTraduitsIncomplets')]
    public function testUnParcoursIncompletNeParaitPasDansLaLangue(array $files, string $missing): void
    {
        $this->parcoursTraduit($files);
        $repository = $this->repository($this->tmp);

        $this->assertNull($repository->findTrack('t', 'en'), 'Le reste du contenu se charge ; le parcours attend sa traduction.');
        $this->assertNotNull($repository->findTrack('t'));
        $this->assertStringContainsString($missing, implode(' ; ', $repository->incompleteTranslations()['en']['t'] ?? []));
    }

    public static function parcoursTraduitsIncomplets(): iterable
    {
        yield 'un exercice non traduit' => [['p/tracks/t/exercises/x/i18n/en/exercise.yaml' => null, 'p/tracks/t/exercises/x/i18n/en/instructions.md' => null], 'exercice « x » non traduit'];
        yield 'un chapitre ajouté' => [['p/tracks/t/i18n/en/track.yaml' => 'title: T en'], 'chapitre « c » sans titre traduit'];
        yield 'un chapitre retiré' => [['p/tracks/t/i18n/en/track.yaml' => "title: T en\nchapters: {c: {title: C en}, d: {title: D en}}"], 'chapitre « d » traduit, mais absent du parcours français'];
        yield 'une fiche de cours non traduite' => [['p/tracks/t/chapters/c/lesson.md' => 'Fiche'], 'fiche de cours du chapitre « c » non traduite'];
    }

    /**
     * Un parcours « t » d'un chapitre « c » et d'un exercice « x », traduits en anglais ; null retire un fichier.
     *
     * @param array<string, string|null> $files
     */
    private function parcoursTraduit(array $files): void
    {
        $filesystem = new Filesystem();
        $filesystem->remove($this->tmp);
        $files += [
            'p/pack.yaml' => "id: p\ntitle: P\ntracks: [t]",
            'p/tracks/t/track.yaml' => "id: t\ntitle: T\nenvironment: symfony-8\nchapters:\n  - {id: c, title: C, exercises: [x]}",
            'p/tracks/t/exercises/x/exercise.yaml' => "id: x\ntitle: X\neditable: [a.php]\nobjectives: [{test: testA, label: A}]",
            'p/tracks/t/exercises/x/instructions.md' => 'Consignes',
            'p/tracks/t/exercises/x/starter/a.php' => '<?php',
            'p/tracks/t/exercises/x/i18n/en/exercise.yaml' => "title: X en\nobjectives: [{test: testA, label: A en}]",
            'p/tracks/t/exercises/x/i18n/en/instructions.md' => 'Instructions',
            'p/tracks/t/i18n/en/track.yaml' => "title: T en\nchapters: {c: {title: C en}}",
        ];
        foreach ($files as $path => $content) {
            if (null !== $content) {
                $filesystem->dumpFile($this->tmp.'/'.$path, $content);
            }
        }
    }

    /**
     * Un exercice « x » à mutant, dont l'apprenant écrit les tests ; $mutants complète sa traduction.
     *
     * @return array<string, string>
     */
    private static function avecMutant(string $mutants): array
    {
        return [
            'p/practice/x/exercise.yaml' => "id: x\ntitle: X\nenvironment: symfony-8\npublished: 2026-09-01\nsummary: S\neditable: [a.php, tests/ATest.php]\nmutants: [{id: double, label: Enregistré deux fois, changes: [{file: a.php, search: '\$commande', replace: '\$commande2'}]}]\nobjectives: [{mutant: double, label: A}]",
            'p/practice/x/starter/tests/ATest.php' => '<?php',
            'p/practice/x/i18n/en/exercise.yaml' => "title: X en\nsummary: S en\nobjectives: [{mutant: double, label: A en}]\n".$mutants,
        ];
    }

    /**
     * Un exercice de Pratique « x » (starter/a.php) et sa traduction anglaise ; null retire un fichier.
     *
     * @param array<string, string|null> $files
     */
    private function traduction(array $files): void
    {
        $filesystem = new Filesystem();
        $filesystem->remove($this->tmp);
        $files += [
            'p/pack.yaml' => "id: p\ntitle: P",
            'p/practice/x/exercise.yaml' => "id: x\ntitle: X\nenvironment: symfony-8\npublished: 2026-09-01\nsummary: S\neditable: [a.php]\nobjectives: [{test: testA, label: A}]",
            'p/practice/x/instructions.md' => 'Consignes',
            'p/practice/x/starter/a.php' => '<?php',
            'p/practice/x/i18n/en/exercise.yaml' => "title: X en\nsummary: S en\nobjectives: [{test: testA, label: A en}]",
            'p/practice/x/i18n/en/instructions.md' => 'Instructions',
        ];
        foreach ($files as $path => $content) {
            if (null !== $content) {
                $filesystem->dumpFile($this->tmp.'/'.$path, $content);
            }
        }
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
        // Une liste écrite comme une valeur simple, ou une faute de syntaxe : une erreur de format, jamais une page 500.
        $e1 = static fn (string $yaml) => ['tracks/t/exercises/e1/exercise.yaml' => $yaml];
        $valide = "id: e1\ntitle: E1\neditable: [a.php]\nobjectives: [{test: testA, label: A}]\n";
        foreach (['concepts' => 'Route', 'hints' => 'un indice', 'readonly' => 'b.php', 'docs' => 'x', 'requests' => 'x', 'setup' => 'cache:clear', 'mutants' => 'x'] as $cle => $valeur) {
            yield $cle.' écrit comme une valeur' => [$e1($valide."{$cle}: {$valeur}"), sprintf('« %s » est une liste', $cle)];
        }
        yield 'editable écrit comme une valeur' => [$e1("id: e1\ntitle: E1\neditable: src/a.php\nobjectives: [{test: testA, label: A}]"), '« editable » est une liste'];
        yield 'objectives écrit comme une valeur' => [$e1("id: e1\ntitle: E1\neditable: [a.php]\nobjectives: x"), '« objectives » est une liste'];
        yield 'un objectif qui n\'est pas un objet' => [$e1("id: e1\ntitle: E1\neditable: [a.php]\nobjectives: [testA]"), 'chaque objectif est un objet'];
        yield 'un titre qui est une liste' => [$e1("id: e1\ntitle: [Bonus, Un détour]\neditable: [a.php]\nobjectives: [{test: testA, label: A}]"), '« title » est un texte'];
        yield 'open qui est une liste' => [$e1($valide.'open: [a.php]'), '« open » est un texte'];
        yield 'faute de syntaxe YAML' => [$e1("id: e1\ntitle: Boss : le grand ménage\n"), 'YAML invalide'];
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
