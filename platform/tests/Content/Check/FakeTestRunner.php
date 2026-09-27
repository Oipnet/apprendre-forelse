<?php

namespace App\Tests\Content\Check;

use App\Content\Check\RunReport;
use App\Content\Check\TestRunner;
use App\Content\Environment;

/** Un lanceur qui ne lance rien : chaque appel rend le rapport que le test a prévu, et est noté. */
final class FakeTestRunner implements TestRunner
{
    /** @var list<array{workdir: string, paths: list<string>}> */
    public array $runs = [];

    /**
     * @param \Closure(string, list<string>): RunReport $reports  rapport par (dossier, fichiers lancés)
     * @param list<string>|null                        $declared les tests que le code déclare ; null : ceux qu'il cite
     */
    public function __construct(
        private readonly \Closure $reports,
        private readonly ?string $unavailable = null,
        private readonly ?array $declared = null,
    ) {
    }

    public function unavailable(Environment $environment): ?string
    {
        return $this->unavailable;
    }

    public function declares(string $testCode, string $test): bool
    {
        return null === $this->declared ? str_contains($testCode, $test) : \in_array($test, $this->declared, true);
    }

    public function testNoun(): string
    {
        return 'test factice';
    }

    public function run(Environment $environment, string $workdir, array $paths = []): RunReport
    {
        $this->runs[] = ['workdir' => $workdir, 'paths' => $paths];

        return ($this->reports)($workdir, $paths);
    }
}
