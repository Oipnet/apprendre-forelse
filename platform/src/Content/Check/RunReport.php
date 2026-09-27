<?php

namespace App\Content\Check;

/**
 * Le rapport d'un lancement de tests : l'état de chaque test, et le message de ceux qui échouent.
 *
 * Sans rapport (erreur fatale, crash, lanceur introuvable), $cases vaut null et la raison est sous la clé « * ».
 */
final readonly class RunReport
{
    public const string GLOBAL = '*';

    /**
     * @param array<string, array{status: string, file: string}>|null $cases    par nom de test ; status : passed, failed ou skipped
     * @param array<string, string>                                    $failures message d'échec par test
     */
    private function __construct(
        public ?array $cases,
        public array $failures,
    ) {
    }

    /**
     * @param array<string, array{status: string, file: string}> $cases
     * @param array<string, string>                              $failures
     */
    public static function of(array $cases, array $failures = []): self
    {
        return new self($cases, $failures);
    }

    public static function none(string $why): self
    {
        return new self(null, [self::GLOBAL => $why]);
    }

    public function ran(): bool
    {
        return null !== $this->cases;
    }

    /**
     * Un test ignoré ou incomplet (markTestIncomplete) ne compte pas comme réussi.
     *
     * @return array<string, bool> par nom de test
     */
    public function passed(): array
    {
        return array_map(static fn (array $case) => 'passed' === $case['status'], $this->cases ?? []);
    }

    /** Au moins un test échoue, ou rien n'a tourné (l'application est cassée). */
    public function hasFailure(): bool
    {
        return null === $this->cases || [] !== array_filter($this->cases, static fn (array $case) => 'passed' !== $case['status']);
    }

    /**
     * Les tests dont le fichier se termine par l'un des chemins donnés.
     *
     * @param list<string> $paths
     *
     * @return array<string, array{status: string, file: string}>
     */
    public function casesIn(array $paths): array
    {
        return array_filter($this->cases ?? [], static function (array $case) use ($paths): bool {
            foreach ($paths as $path) {
                if (str_ends_with($case['file'], '/'.$path)) {
                    return true;
                }
            }

            return false;
        });
    }
}
