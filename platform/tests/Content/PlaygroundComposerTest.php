<?php

namespace App\Tests\Content;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Le petit Composer du projet sans framework (environments/php-8/.forelse/composer.php), lancé par le PHP natif comme
 * le fait le vérificateur. Dans le navigateur, c'est le même script.
 */
final class PlaygroundComposerTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../environments/php-8/.forelse/composer.php';

    private string $projet;

    protected function setUp(): void
    {
        $this->projet = sys_get_temp_dir().'/composer-playground-'.bin2hex(random_bytes(4));
        $fs = new Filesystem();
        $fs->mkdir([$this->projet.'/.forelse', $this->projet.'/vendor/composer', $this->projet.'/src']);
        $fs->copy(self::SCRIPT, $this->projet.'/.forelse/composer.php');
        $fs->copy(__DIR__.'/../../vendor/composer/ClassLoader.php', $this->projet.'/vendor/composer/ClassLoader.php');
        // Les tables du vrai Composer pour l'archive de l'environnement : App\ (le projet) et un paquet de vendor/.
        $fs->dumpFile($this->projet.'/vendor/composer/autoload_psr4.php', "<?php\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\nreturn ['Outil\\\\' => [\$vendorDir.'/acme/outil/src'], 'App\\\\' => [\$baseDir.'/src']];\n");
        $fs->dumpFile($this->projet.'/vendor/acme/outil/src/Marteau.php', "<?php\nnamespace Outil;\nfinal class Marteau {}\n");
        $fs->dumpFile($this->projet.'/vendor/composer/installed.json', json_encode(['packages' => [['name' => 'acme/outil', 'version' => '2.0.1']]]));
        // Le vendor/autoload.php de l'archive, réduit à ce qui compte ici : la table PSR-4 figée par le vrai Composer.
        $fs->dumpFile($this->projet.'/vendor/autoload.php', "<?php\nrequire_once __DIR__.'/composer/ClassLoader.php';\n\$l = new Composer\\Autoload\\ClassLoader(__DIR__);\nforeach (require __DIR__.'/composer/autoload_psr4.php' as \$p => \$d) { \$l->setPsr4(\$p, \$d); }\n\$l->register(true);\nreturn \$l;\n");
        $fs->dumpFile($this->projet.'/src/Article.php', "<?php\n\nnamespace Gazette;\n\nfinal class Article {}\n");
        $this->composerJson(['autoload' => ['psr-4' => ['Gazette\\' => 'src/']], 'config' => ['sort-packages' => true]]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projet);
    }

    public function testDumpAutoloadRelitComposerJson(): void
    {
        self::assertSame('non', $this->charge('Gazette\Article'), 'avant le dump, l\'autoload de l\'archive ne connaît que App\\');

        [$code, $sortie] = $this->composer('dump-autoload');

        self::assertSame(0, $code, $sortie);
        self::assertStringContainsString('Generated autoload files', $sortie);
        self::assertSame('oui', $this->charge('Gazette\Article'));
        self::assertSame('oui', $this->charge('Outil\Marteau'), 'les paquets de l\'environnement restent chargés');
        self::assertSame('non', $this->charge('App\Article'), 'la règle App\ de l\'archive a disparu avec composer.json');
        // Relancer ne perd rien : les tables de vendor/composer/ ne sont jamais réécrites.
        self::assertSame(0, $this->composer('dump-autoload')[0]);
        self::assertSame('oui', $this->charge('Outil\Marteau'));
    }

    public function testUnPrefixePsr4SansAntislashEstRefuseCommeParComposer(): void
    {
        $this->composerJson(['autoload' => ['psr-4' => ['Gazette' => 'src/']]]);

        [$code, $sortie] = $this->composer('dump-autoload');

        self::assertSame(1, $code);
        self::assertStringContainsString('A non-empty PSR-4 prefix must end with a namespace separator.', $sortie);
    }

    public function testUneVirguleDeTropEstSignaleeAvecSaLigne(): void
    {
        file_put_contents($this->projet.'/composer.json', "{\n    \"autoload\": {\n        \"psr-4\": {\"Gazette\\\\\": \"src/\"},\n    }\n}\n");

        [$code, $sortie] = $this->composer('dump-autoload');

        self::assertSame(1, $code);
        self::assertStringContainsString('does not contain valid JSON', $sortie);
        self::assertStringContainsString('line 3', $sortie);
    }

    public function testDumpOptimiseEcarteUneClasseHorsPsr4(): void
    {
        file_put_contents($this->projet.'/src/Rubrique.php', "<?php\n\nnamespace Gazette\\Modele;\n\nenum Rubrique: string {}\n");

        [$code, $sortie] = $this->composer('dump-autoload', '-o');

        self::assertSame(0, $code, $sortie);
        self::assertStringContainsString('Class Gazette\Modele\Rubrique located in ./src/Rubrique.php does not comply with psr-4 autoloading standard (rule: Gazette\ => ./src). Skipping.', $sortie);
        self::assertStringContainsString('containing 1 classes', $sortie, 'Gazette\Article seulement : Outil\ garde sa règle PSR-4');
    }

    public function testRequireAjouteUnPaquetDuCatalogueDejaPose(): void
    {
        $this->paquetPose();

        [$code, $sortie] = $this->composer('require', 'nikic/fast-route');

        self::assertSame(0, $code, $sortie);
        self::assertStringContainsString('Using version ^1.3 for nikic/fast-route', $sortie);
        self::assertStringContainsString('- Installing nikic/fast-route (v1.3.0)', $sortie);
        $composer = json_decode((string) file_get_contents($this->projet.'/composer.json'), true);
        self::assertSame(['nikic/fast-route' => '^1.3'], $composer['require']);
        $lock = json_decode((string) file_get_contents($this->projet.'/composer.lock'), true);
        self::assertContains('nikic/fast-route', array_column($lock['packages'], 'name'));
        self::assertSame('oui', $this->charge('FastRoute\RouteCollector'));
        self::assertSame('oui', $this->appel('FastRoute\simpleDispatcher'), 'les fichiers « files » du paquet sont chargés');
    }

    public function testRequireDUnPaquetHorsCatalogueEchoue(): void
    {
        [$code, $sortie] = $this->composer('require', 'slim/slim');

        self::assertSame(1, $code);
        self::assertStringContainsString('Could not find package slim/slim.', $sortie);
        self::assertArrayNotHasKey('require', json_decode((string) file_get_contents($this->projet.'/composer.json'), true));
    }

    public function testRemoveRetireLePaquet(): void
    {
        $this->paquetPose();
        $this->composer('require', 'nikic/fast-route:^1.2');
        self::assertSame('^1.2', json_decode((string) file_get_contents($this->projet.'/composer.json'), true)['require']['nikic/fast-route']);

        [$code, $sortie] = $this->composer('remove', 'nikic/fast-route');

        self::assertSame(0, $code, $sortie);
        self::assertStringContainsString('- Removing nikic/fast-route', $sortie);
        self::assertSame([], json_decode((string) file_get_contents($this->projet.'/composer.json'), true)['require']);
        self::assertSame('non', $this->charge('FastRoute\RouteCollector'));
    }

    public function testInstallPoseLesPaquetsDuCatalogueDepuisLeursArchives(): void
    {
        // Ce que build-paquets.php écrit dans public/envs : le catalogue, et une archive zip par paquet.
        $envs = $this->projet.'-envs';
        mkdir($envs);
        $zip = new \PharData($envs.'/php-8.paquet.nikic--fast-route.abc.zip');
        foreach ($this->fichiersDuPaquet() as $chemin => $contenu) {
            $zip->addFromString($chemin, $contenu);
        }
        unset($zip);
        file_put_contents($envs.'/php-8.paquets.json', json_encode(['paquets' => [['nom' => 'nikic/fast-route', 'archive' => 'php-8.paquet.nikic--fast-route.abc.zip']]]));
        $this->composerJson(['require' => ['nikic/fast-route' => '^1.3'], 'autoload' => ['psr-4' => ['Gazette\\' => 'src/']]]);

        try {
            [$code, $sortie] = $this->composerAvec(['FORELSE_PAQUETS' => $envs.'/php-8.paquets.json'], 'install');
        } finally {
            (new Filesystem())->remove($envs);
        }

        self::assertSame(0, $code, $sortie);
        self::assertSame('oui', $this->charge('FastRoute\RouteCollector'));
        self::assertSame('oui', $this->charge('Gazette\Article'));
    }

    public function testInstallSansLeCatalogueDitCeQuiManque(): void
    {
        $this->composerJson(['require' => ['nikic/fast-route' => '^1.3']]);

        [$code, $sortie] = $this->composer('install', '--quiet');

        self::assertSame(1, $code);
        self::assertStringContainsString('Could not find package nikic/fast-route.', $sortie);
    }

    /** @param array<string, mixed> $composer */
    private function composerJson(array $composer): void
    {
        file_put_contents($this->projet.'/composer.json', json_encode($composer, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    /** Ce que le navigateur dépose avant `composer require` : les fichiers de l'archive du paquet. */
    private function paquetPose(): void
    {
        foreach ($this->fichiersDuPaquet() as $chemin => $contenu) {
            (new Filesystem())->dumpFile($this->projet.'/'.$chemin, $contenu);
        }
    }

    /** @return array<string, string> */
    private function fichiersDuPaquet(): array
    {
        return [
            'vendor/nikic/fast-route/src/RouteCollector.php' => "<?php\nnamespace FastRoute;\nclass RouteCollector {}\n",
            'vendor/nikic/fast-route/src/functions.php' => "<?php\nnamespace FastRoute;\nfunction simpleDispatcher() { return 'oui'; }\n",
            'vendor/forelse-paquets/nikic--fast-route.php' => "<?php\nreturn ".var_export([
                'nom' => 'nikic/fast-route',
                'version' => 'v1.3.0',
                'paquets' => ['nikic/fast-route' => 'v1.3.0'],
                'classes' => ['FastRoute\RouteCollector' => 'nikic/fast-route/src/RouteCollector.php'],
                'fichiers' => ['abc123' => 'nikic/fast-route/src/functions.php'],
            ], true).";\n",
        ];
    }

    /** @return array{0: int, 1: string} */
    private function composer(string ...$arguments): array
    {
        return $this->composerAvec([], ...$arguments);
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string}
     */
    private function composerAvec(array $env, string ...$arguments): array
    {
        $process = new Process([\PHP_BINARY, '.forelse/composer.php', ...$arguments], $this->projet, $env);
        $process->run();

        return [$process->getExitCode() ?? 255, $process->getOutput().$process->getErrorOutput()];
    }

    private function charge(string $classe): string
    {
        return $this->php(sprintf('echo class_exists(%s) ? "oui" : "non";', var_export($classe, true)));
    }

    private function appel(string $fonction): string
    {
        return $this->php(sprintf('echo %s();', $fonction));
    }

    private function php(string $code): string
    {
        $process = new Process([\PHP_BINARY, '-r', 'require "vendor/autoload.php"; '.$code], $this->projet);
        $process->run();

        return trim($process->getOutput().$process->getErrorOutput());
    }
}
