<?php

namespace App\Export;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/** CSV « à la française » (séparateur « ; », BOM UTF-8) : s'ouvre directement dans Excel ou LibreOffice. */
final class CsvExport
{
    /**
     * @param list<string>               $header
     * @param iterable<list<scalar|null>> $rows
     */
    public static function write(array $header, iterable $rows, ?string $path, OutputInterface $output): int
    {
        $buffer = fopen('php://temp', 'r+');
        fwrite($buffer, "\u{FEFF}");
        fputcsv($buffer, $header, ';', '"', '');
        $count = 0;
        foreach ($rows as $row) {
            fputcsv($buffer, array_map(static fn ($v) => \is_bool($v) ? ($v ? 'oui' : 'non') : $v, $row), ';', '"', '');
            ++$count;
        }
        rewind($buffer);
        $csv = (string) stream_get_contents($buffer);
        fclose($buffer);

        if (null === $path) {
            $output->write($csv, false, OutputInterface::OUTPUT_RAW);
        } else {
            (new Filesystem())->dumpFile($path, $csv);
        }

        return $count;
    }
}
