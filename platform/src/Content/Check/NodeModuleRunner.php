<?php

namespace App\Content\Check;

use App\Content\Environment;
use App\Content\Framework\FrameworkProfile;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Tests lancés par le module que le framework déclare (`testModule`) : le même runner que dans le
 * navigateur, donc le même verdict.
 *
 * Le moteur ne connaît ni le module ni son emplacement — il demande à Node de résoudre le
 * spécificateur depuis le playground, là où les paquets de runtime sont installés. Ajouter un
 * runtime avec son propre lanceur ne demande donc rien ici.
 *
 * Node tourne sans les variables de la plateforme (ProcessEnvironment), comme PHPUnit : le code de test d'un pack
 * ne doit lire ni DATABASE_URL, ni APP_SECRET, ni les clés Stripe ou Anthropic dans process.env.
 */
#[AsTaggedItem(FrameworkProfile::VITEST)]
final readonly class NodeModuleRunner implements TestRunner
{
    public function __construct(
        private ProcessEnvironment $processEnvironment,
        /** Dossier de la plateforme : le playground est à côté. */
        #[Autowire('%kernel.project_dir%')]
        private string $platformDir = __DIR__.'/../../..',
    ) {
    }

    public function unavailable(Environment $environment): ?string
    {
        // Il faut Node, et le paquet qui fournit le module. Ni l'un ni l'autre ne nomme un framework en particulier.
        $module = $environment->framework->testModule;
        if (null === $this->nodeBinary()) {
            return sprintf('Framework « %s » : Node.js est introuvable, impossible de lancer ses tests.', $environment->framework->id);
        }
        if (null === $module || null === $this->resolve($module)) {
            return sprintf('Framework « %s » : le module de test %s est introuvable (npm install, dans playground/).', $environment->framework->id, null === $module ? 'n\'est pas déclaré' : sprintf('« %s »', $module));
        }

        return null;
    }

    /** Un it() ou test() de ce titre. */
    public function declares(string $testCode, string $test): bool
    {
        return 1 === preg_match('/\b(?:it|test)(?:\.\w+)*\s*\(\s*([\'"`])'.preg_quote($test, '/').'\1/u', $testCode);
    }

    public function testNoun(): string
    {
        return 'test it()';
    }

    public function run(Environment $environment, string $workdir, array $paths = []): RunReport
    {
        $framework = $environment->framework;
        $module = $framework->testModule;
        if (null === $module) {
            return RunReport::none(sprintf('Le framework « %s » ne lance pas PHPUnit et ne déclare aucun module de test (voir FrameworkProfile::$testModule).', $framework->id));
        }
        $script = $this->resolve($module);
        if (null === $script) {
            return RunReport::none(sprintf('Module de test « %s » introuvable : installez le paquet qui le fournit (npm install, dans playground/).', $module));
        }

        $process = new Process([(string) $this->nodeBinary(), '--experimental-transform-types', '--no-warnings', $script, $workdir, ...$paths], $workdir, $this->environment(), timeout: 120);
        $process->run();
        $report = json_decode($process->getOutput(), true);
        if (!\is_array($report) || !\is_array($report['cases'] ?? null)) {
            return RunReport::none(trim($process->getErrorOutput()) ?: sprintf('« %s » n\'a rien rapporté.', $module));
        }
        $cases = [];
        $failures = [];
        foreach ($report['cases'] as $case) {
            $cases[(string) $case['name']] = ['status' => 'error' === $case['status'] ? 'failed' : (string) $case['status'], 'file' => (string) ($case['file'] ?? '')];
            if (\in_array($case['status'], ['failed', 'error'], true)) {
                $failures[(string) $case['name']] = (string) ($case['message'] ?? '');
            }
        }

        return $cases ? RunReport::of($cases, $failures) : RunReport::none((string) ($report['output'] ?? ''));
    }

    /**
     * Le fichier derrière un spécificateur de module, résolu par Node depuis le playground.
     *
     * C'est `node_modules` qui sait où vit un paquet, pas le moteur : un runtime peut être un lien
     * local, une dépendance publiée ou un dossier tiers, cela ne regarde personne ici.
     */
    private function resolve(string $module): ?string
    {
        $node = $this->nodeBinary();
        if (null === $node) {
            return null;
        }
        $process = new Process(
            [$node, '--input-type=module', '-e', sprintf('process.stdout.write(import.meta.resolve(%s))', json_encode($module, \JSON_THROW_ON_ERROR))],
            $this->platformDir.'/../playground',
            $this->environment(),
            timeout: 30,
        );
        $process->run();
        $url = trim($process->getOutput());

        // rawurldecode : l'URL renvoyée par Node est percent-encodée (un dossier « formation symfony »
        // arrive en « formation%20symfony »), et Node ne retrouve pas ce fichier-là.
        return $process->isSuccessful() && str_starts_with($url, 'file://')
            ? rawurldecode((string) parse_url($url, \PHP_URL_PATH))
            : null;
    }

    /**
     * Sans les variables de la plateforme, ni APP_ENV ni NODE_ENV : le lanceur choisit lui-même son mode.
     *
     * @return array<string, string|false>
     */
    private function environment(): array
    {
        return [...$this->processEnvironment->isolated(), 'APP_ENV' => false, 'NODE_ENV' => false];
    }

    private function nodeBinary(): ?string
    {
        return (new ExecutableFinder())->find('node');
    }
}
