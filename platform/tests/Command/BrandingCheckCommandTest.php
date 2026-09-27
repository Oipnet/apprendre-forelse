<?php

namespace App\Tests\Command;

use App\Command\BrandingCheckCommand;
use App\Tests\BrandingTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** app:marque:verifier : la marque lue en entier, et la clé à corriger. */
final class BrandingCheckCommandTest extends TestCase
{
    use BrandingTrait;

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/marque-verifier-'.bin2hex(random_bytes(6));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp.'/marque.yaml');
        @rmdir($this->tmp);
    }

    private function tester(string $directory): CommandTester
    {
        return new CommandTester(new Command(null, new BrandingCheckCommand(self::branding($directory))));
    }

    public function testSansMarqueCEstCelleDuMoteur(): void
    {
        $tester = $this->tester('');

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('marque du moteur', $tester->getDisplay());
    }

    public function testUneMarqueInvalideEstSignaleeAvecSaCle(): void
    {
        file_put_contents($this->tmp.'/marque.yaml', "name: A\neditor:\n  accent: bleu\n");
        $tester = $this->tester($this->tmp);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('editor.accent', $tester->getDisplay());
    }

    public function testLExempleLivreEstValable(): void
    {
        $tester = $this->tester(\dirname(__DIR__, 3).'/examples/marque');

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Atelier Bigorneau', $tester->getDisplay());
    }
}
