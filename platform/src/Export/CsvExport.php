<?php

namespace App\Export;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/** CSV « à la française » (séparateur « ; », BOM UTF-8) : s'ouvre directement dans Excel ou LibreOffice. */
final class CsvExport
{
    /** Premiers caractères qui font d'une cellule une formule dans un tableur. */
    private const string FORMULA_PREFIXES = "=+-@\t\r";

    /**
     * @param list<string>               $header
     * @param iterable<list<scalar|null>> $rows
     */
    public static function write(array $header, iterable $rows, ?string $path, OutputInterface $output): int
    {
        $buffer = fopen('php://temp', 'r+');
        fwrite($buffer, "\u{FEFF}");
        fputcsv($buffer, array_map(self::cell(...), $header), ';', '"', '');
        $count = 0;
        foreach ($rows as $row) {
            fputcsv($buffer, array_map(self::cell(...), $row), ';', '"', '');
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

    /**
     * Un texte saisi par un apprenant (pseudo, retour) qui commence comme une formule (« =HYPERLINK(…) ») est
     * préfixé d'une apostrophe : le tableur l'affiche comme du texte au lieu de l'exécuter à l'ouverture.
     * Les nombres restent des nombres, négatifs compris.
     */
    private static function cell(bool|int|float|string|null $value): int|float|string|null
    {
        if (\is_bool($value)) {
            return $value ? 'oui' : 'non';
        }
        if (\is_string($value) && '' !== $value && str_contains(self::FORMULA_PREFIXES, $value[0])) {
            return "'".$value;
        }

        return $value;
    }
}
