<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Build;

/** La sortie « plain » de BuildKit : #N [étape], lignes horodatées, DONE / CACHED / ERROR. */
final class BuildOutput
{
    private string $text = '';
    private int $counter = 0;
    private ?array $pendingError = null;

    public function line(string $line): void
    {
        $this->text .= $line."\n";
    }

    public function blank(): void
    {
        $this->text .= "\n";
    }

    public function step(string $name): int
    {
        $number = ++$this->counter;
        $this->line(sprintf('#%d %s', $number, $name));

        return $number;
    }

    public function stepLine(int $step, string $text): void
    {
        $this->line(sprintf('#%d %s', $step, $text));
    }

    public function stepOutput(int $step, string $output, float $seconds): void
    {
        $lines = explode("\n", rtrim($output, "\n"));
        if ($output === '') {
            return;
        }
        $count = max(1, \count($lines));
        foreach ($lines as $index => $line) {
            $this->line(sprintf('#%d %.3f %s', $step, 0.2 + $seconds * ($index / $count), $line));
        }
    }

    public function stepCached(int $step): void
    {
        $this->line(sprintf('#%d CACHED', $step));
    }

    public function stepDone(int $step, ?float $seconds): void
    {
        if ($this->pendingError !== null && $this->pendingError[0] === $step) {
            return;
        }
        if ($seconds !== null) {
            $this->line(sprintf('#%d DONE %.1fs', $step, $seconds));
        }
        $this->blank();
    }

    public function stepError(int $step, string $message): void
    {
        $this->line(sprintf('#%d ERROR: %s', $step, $message));
        $this->blank();
    }

    /** Erreur d'un RUN : le message arrive avec l'échec du build ; on garde la sortie pour le bloc récapitulatif. */
    public function stepErrorPending(int $step, string $name, string $output): void
    {
        $this->pendingError = [$step, $name, $output];
    }

    public function errorBlock(string $name, string $output): void
    {
        $this->line('------');
        $this->line(' > '.$name.':');
        foreach (\array_slice(explode("\n", rtrim($output, "\n")), -10) as $line) {
            if ($line !== '') {
                $this->line(sprintf('%.3f %s', 0.3, $line));
            }
        }
        $this->line('------');
    }

    /** Extrait du Dockerfile autour de la ligne en erreur, comme BuildKit (précédé du récapitulatif d'un RUN en échec). */
    public function excerpt(string $dockerfile, int $line, string $detail = '', ?int $endLine = null): void
    {
        if ($this->pendingError !== null) {
            [$step, $name, $output] = $this->pendingError;
            $this->pendingError = null;
            $this->line(sprintf('#%d ERROR: %s', $step, $detail));
            $this->errorBlock($name, $output);
        }
        $lines = explode("\n", $dockerfile);
        $endLine = max($line, $endLine ?? $line);
        $this->line('Dockerfile:'.$line.($endLine > $line ? '-'.$endLine : ''));
        $this->line('--------------------');
        $start = max(1, $line - 2);
        $end = min(\count($lines), $endLine + 2);
        for ($i = $start; $i <= $end; ++$i) {
            $this->line(rtrim(sprintf('%4d | %s %s', $i, $i >= $line && $i <= $endLine ? '>>>' : '   ', $lines[$i - 1])));
        }
        $this->line('--------------------');
    }

    public function text(): string
    {
        return $this->text;
    }

    public static function bytes(int $bytes): string
    {
        return match (true) {
            $bytes < 1000 => $bytes.'B',
            $bytes < 1_000_000 => sprintf('%.2fkB', $bytes / 1000),
            default => sprintf('%.2fMB', $bytes / 1_000_000),
        };
    }

    public static function megabytes(int $bytes): string
    {
        return sprintf('%.2fMB', $bytes / 1_000_000);
    }
}
