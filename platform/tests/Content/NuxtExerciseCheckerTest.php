<?php

namespace App\Tests\Content;

use App\Content\Check\ExerciseChecker;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;

/** Exercices Nuxt : leurs tests Vitest sont lancés par le simulateur (packages/simulateur-nuxt), sous Node. */
final class NuxtExerciseCheckerTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';
    private string $tmp;

    protected function setUp(): void
    {
        if (null === (new ExecutableFinder())->find('node') || !is_dir(self::ROOT.'/packages/simulateur-nuxt/node_modules')) {
            $message = 'Node.js ou les dépendances du simulateur Nuxt manquent (npm ci dans packages/simulateur-nuxt).';
            if (filter_var(getenv('CI'), \FILTER_VALIDATE_BOOL)) {
                $this->fail($message);
            }
            $this->markTestSkipped($message);
        }
        $this->tmp = sys_get_temp_dir().'/nuxt-checker-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    /**
     * Copie du pack de démo avec un chapitre Nuxt et ses exercices.
     *
     * @param array<string, array<string, string>> $exercises fichiers par exercice
     */
    private function checker(array $exercises): array
    {
        $filesystem = new Filesystem();
        $filesystem->mirror(self::ROOT.'/examples/packs/demo', $this->tmp.'/demo');
        $track = $this->tmp.'/demo/tracks/decouverte';
        $chapter = "  - id: refuge\n    title: Le refuge\n    environment: nuxt-4\n    exercises:\n".implode('', array_map(static fn (string $id) => "      - {$id}\n", array_keys($exercises)));
        $filesystem->appendToFile($track.'/track.yaml', $chapter);
        foreach ($exercises as $id => $files) {
            foreach ($files as $path => $content) {
                $filesystem->dumpFile($track.'/exercises/'.$id.'/'.$path, $content);
            }
        }
        $environments = new EnvironmentRegistry(self::ROOT.'/environments');
        $content = new ContentRepository([$this->tmp], $environments, new Version(self::ROOT.'/VERSION'));

        return [$content, new ExerciseChecker($content, $environments, self::ROOT.'/platform')];
    }

    private const string PAGE_DE_DEPART = "<template>\n\t<h1>Nuxt</h1>\n</template>\n";
    private const string PAGE_SOLUTION = "<template>\n\t<h1>Refuge du Pic Tordu</h1>\n</template>\n";

    private static function exerciceAvecTestsCaches(string $titreDuTest = 'affiche le nom du refuge'): array
    {
        return [
            'exercise.yaml' => <<<YAML
                id: 90-panneau
                title: Le panneau d'entrée
                open: app/app.vue
                editable: [app/app.vue]
                objectives:
                  - {test: "{$titreDuTest}", label: Le refuge s'affiche}
                YAML,
            'instructions.md' => 'Affichez le nom du refuge.',
            'starter/app/app.vue' => self::PAGE_DE_DEPART,
            'solution/app/app.vue' => self::PAGE_SOLUTION,
            'tests/panneau.test.ts' => <<<'TS'
                import { describe, expect, it } from 'vitest'
                import { $fetch, setup } from '@nuxt/test-utils/e2e'

                await setup()

                describe('le panneau', () => {
                	it('affiche le nom du refuge', async () => {
                		expect(await $fetch('/')).toContain('<h1>Refuge du Pic Tordu</h1>')
                	})
                })
                TS,
        ];
    }

    public function testUnExerciceNuxtEstVerifieParSesTestsVitest(): void
    {
        [$content, $checker] = $this->checker(['90-panneau' => self::exerciceAvecTestsCaches()]);

        $result = $checker->check($content->findExercise('decouverte', '90-panneau'));

        $this->assertSame([], $result->errors);
    }

    public function testUneSolutionQuiNePassePasEstSignalee(): void
    {
        $exercise = self::exerciceAvecTestsCaches();
        $exercise['solution/app/app.vue'] = "<template>\n\t<h1>Refuge</h1>\n</template>\n";
        [$content, $checker] = $this->checker(['90-panneau' => $exercise]);

        $errors = implode("\n", $checker->check($content->findExercise('decouverte', '90-panneau'))->errors);

        $this->assertStringContainsString('Solution : le test affiche le nom du refuge échoue', $errors);
        $this->assertStringContainsString('AssertionError: expected', $errors);
    }

    public function testUnObjectifSansTestDeCeTitreEstSignale(): void
    {
        [$content, $checker] = $this->checker(['90-panneau' => self::exerciceAvecTestsCaches('affiche le panneau')]);

        $errors = implode("\n", $checker->check($content->findExercise('decouverte', '90-panneau'))->errors);

        $this->assertStringContainsString('Objectif « affiche le panneau » : aucun test it() de ce nom dans tests/.', $errors);
    }

    public function testDesTestsVitestDeLApprenantNotesParDesMutants(): void
    {
        $test = static fn (string $body) => "import { expect, it } from 'vitest'\nimport { \$fetch } from '@nuxt/test-utils/e2e'\n\nit('le refuge', async () => {\n{$body}\n})\n";
        [$content, $checker] = $this->checker(['91-mes-tests' => [
            'exercise.yaml' => <<<'YAML'
                id: 91-mes-tests
                title: Mes tests du refuge
                open: tests/refuge.test.ts
                editable: [tests/refuge.test.ts]
                mutants:
                  - id: nom
                    label: le nom du refuge disparaît
                    changes: [{file: app/app.vue, search: Refuge du Pic Tordu, replace: Refuge}]
                objectives:
                  - {own-tests: pass, label: Vos tests passent}
                  - {mutant: nom, label: Ils voient le nom disparaître}
                YAML,
            'instructions.md' => 'Testez la page d’accueil.',
            'starter/app/app.vue' => self::PAGE_SOLUTION,
            'starter/tests/refuge.test.ts' => $test("\texpect(true).toBe(false)"),
            'solution/tests/refuge.test.ts' => $test("\texpect(await \$fetch('/')).toContain('Refuge du Pic Tordu')"),
        ]]);

        $result = $checker->check($content->findExercise('decouverte', '91-mes-tests'));

        $this->assertSame([], $result->errors);
    }
}
