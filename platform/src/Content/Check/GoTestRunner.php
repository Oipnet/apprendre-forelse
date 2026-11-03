<?php

namespace App\Content\Check;

use App\Content\Environment;
use App\Content\Framework\FrameworkProfile;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Tests Go (fonctions TestXxx du paquet testing), lancés comme les tests Vitest : par le module Node que le
 * framework déclare (`testModule`), avec le binaire du navigateur. Seule la façon de reconnaître un test change.
 */
#[AsTaggedItem(FrameworkProfile::GOTEST)]
final readonly class GoTestRunner implements TestRunner
{
    public function __construct(
        private NodeModuleRunner $node,
    ) {
    }

    public function unavailable(Environment $environment): ?string
    {
        return $this->node->unavailable($environment);
    }

    /** Une fonction `func TestXxx(t *testing.T)` de ce nom. */
    public function declares(string $testCode, string $test): bool
    {
        return 1 === preg_match('/^func\s+'.preg_quote($test, '/').'\s*\(\s*\w+\s+\*testing\.T\s*\)/mu', $testCode);
    }

    public function testNoun(): string
    {
        return 'fonction de test Go (func TestXxx(t *testing.T))';
    }

    public function run(Environment $environment, string $workdir, array $paths = []): RunReport
    {
        return $this->node->run($environment, $workdir, $paths);
    }
}
