<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Cli;

use Forelse\DockerSim\State\Container;

final class Format
{
    /**
     * Tableau aligné sur trois espaces, comme la CLI Docker.
     *
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public static function table(array $headers, array $rows): string
    {
        $widths = array_map('mb_strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                $widths[$index] = max($widths[$index] ?? 0, mb_strlen($cell));
            }
        }
        $line = static function (array $cells) use ($widths): string {
            $out = '';
            $last = \count($cells) - 1;
            foreach ($cells as $index => $cell) {
                $out .= $index === $last ? $cell : $cell.str_repeat(' ', $widths[$index] - mb_strlen($cell) + 3);
            }

            return rtrim($out)."\n";
        };
        $text = $line($headers);
        foreach ($rows as $row) {
            $text .= $line($row);
        }

        return $text;
    }

    public static function ago(int $timestamp, bool $suffix = true): string
    {
        $seconds = max(0, time() - $timestamp);
        $text = match (true) {
            $seconds < 1 => 'Less than a second',
            $seconds < 60 => $seconds.' second'.($seconds > 1 ? 's' : ''),
            $seconds < 120 => 'About a minute',
            $seconds < 3600 => intdiv($seconds, 60).' minutes',
            $seconds < 7200 => 'About an hour',
            $seconds < 86400 => intdiv($seconds, 3600).' hours',
            $seconds < 172800 => '1 day',
            $seconds < 14 * 86400 => intdiv($seconds, 86400).' days',
            $seconds < 60 * 86400 => intdiv($seconds, 7 * 86400).' weeks',
            default => intdiv($seconds, 30 * 86400).' months',
        };

        return $suffix ? $text.' ago' : $text;
    }

    public static function size(int $bytes): string
    {
        // Trois chiffres significatifs, comme Docker (units.HumanSizeWithPrecision) : 24.6kB, 3.14MB, 586MB.
        $units = ['B', 'kB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1000 && $unit < \count($units) - 1) {
            $value /= 1000;
            ++$unit;
        }

        return rtrim(rtrim(sprintf('%.3g', $value), '0'), '.').$units[$unit];
    }

    public static function status(Container $container): string
    {
        return match ($container->status) {
            Container::RUNNING => 'Up '.self::ago($container->startedAt, false).($container->health !== null ? ' ('.$container->health.')' : ''),
            Container::EXITED => sprintf('Exited (%d) %s', $container->exitCode, self::ago($container->finishedAt ?: $container->createdAt)),
            Container::RESTARTING => sprintf('Restarting (%d) %s', $container->exitCode, self::ago($container->finishedAt ?: $container->createdAt)),
            default => 'Created',
        };
    }

    /** @param list<string> $exposed ports exposés par l'image (« 9000/tcp ») : docker ps les montre même non publiés */
    public static function ports(Container $container, bool $running = true, array $exposed = []): string
    {
        if (!$container->isRunning() && $running) {
            return '';
        }
        $parts = [];
        $published = [];
        foreach ($container->ports as $port) {
            $published[$port['container'].'/'.$port['protocol']] = true;
            if ($port['ip'] === '0.0.0.0') {
                $parts[] = sprintf('0.0.0.0:%d->%d/%s', $port['host'], $port['container'], $port['protocol']);
                $parts[] = sprintf('[::]:%d->%d/%s', $port['host'], $port['container'], $port['protocol']);
            } else {
                $parts[] = sprintf('%s:%d->%d/%s', $port['ip'], $port['host'], $port['container'], $port['protocol']);
            }
        }
        $unpublished = [];
        foreach ($exposed as $port) {
            $port = str_contains((string) $port, '/') ? (string) $port : $port.'/tcp';
            if (!isset($published[$port])) {
                $unpublished[] = $port;
            }
        }
        sort($unpublished, \SORT_NATURAL);

        return implode(', ', [...$parts, ...array_unique($unpublished)]);
    }

    /** « docker-php-entrypoi… » : la commande tronquée à 20 caractères. */
    public static function command(Container $container): string
    {
        $command = implode(' ', $container->command);

        return '"'.(mb_strlen($command) > 20 ? mb_substr($command, 0, 20).'…' : $command).'"';
    }
}
