<?php

namespace App\Tests\Export;

use App\Export\CsvExport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class CsvExportTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function formules(): iterable
    {
        yield 'égal' => ['=HYPERLINK("https://example.test","Cliquez")'];
        yield 'plus' => ['+1+1'];
        yield 'moins' => ['-1+1'];
        yield 'arobase' => ['@SUM(A1:A2)'];
        yield 'tabulation' => ["\t=1+1"];
        yield 'retour chariot' => ["\r=1+1"];
    }

    #[DataProvider('formules')]
    public function testUneCelluleQuiCommenceCommeUneFormuleDevientDuTexte(string $formule): void
    {
        $this->assertSame(["'".$formule], $this->roundTrip([[$formule]])[1]);
    }

    public function testLEnteteEstEchappeAussi(): void
    {
        $this->assertSame(["'=1+1"], $this->roundTrip([], ['=1+1'])[0]);
    }

    public function testLeResteNeChangePas(): void
    {
        $row = $this->roundTrip([['Ada', 'a=b', '', null, -3, 2.5, true, false]])[1];

        $this->assertSame(['Ada', 'a=b', '', '', '-3', '2.5', 'oui', 'non'], $row, 'Un nombre négatif reste un nombre.');
    }

    /**
     * @param list<list<scalar|null>> $rows
     * @param list<string>            $header
     *
     * @return list<list<string>>
     */
    private function roundTrip(array $rows, array $header = ['colonne']): array
    {
        $output = new BufferedOutput();
        CsvExport::write($header, $rows, null, $output);
        $csv = substr($output->fetch(), \strlen("\u{FEFF}"));

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $lines = [];
        while (false !== ($line = fgetcsv($stream, null, ';', '"', ''))) {
            $lines[] = $line;
        }

        return $lines;
    }
}
