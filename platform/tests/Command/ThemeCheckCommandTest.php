<?php

namespace App\Tests\Command;

use App\Command\ThemeCheckCommand;
use App\Tests\ThemeTrait;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** app:theme:verifier : le thème lu en entier, et la clé à corriger. */
final class ThemeCheckCommandTest extends TestCase
{
    use ThemeTrait;

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/theme-verifier-'.bin2hex(random_bytes(6));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    private function tester(string $directory): CommandTester
    {
        return new CommandTester(new Command(null, new ThemeCheckCommand(self::theme($directory))));
    }

    public function testSansThemeCEstCeluiDuMoteur(): void
    {
        $tester = $this->tester('');

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('thème du moteur', $tester->getDisplay());
    }

    public function testUnThemeInvalideEstSignaleAvecSaCle(): void
    {
        file_put_contents($this->tmp.'/theme.yaml', "name: A\neditor:\n  accent: bleu\n");
        $tester = $this->tester($this->tmp);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('editor.accent', $tester->getDisplay());
    }

    public function testLExempleLivreEstValable(): void
    {
        $tester = $this->tester(\dirname(__DIR__, 3).'/examples/themes/atelier-bigorneau');

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Atelier Bigorneau', $tester->getDisplay());
        $this->assertStringNotContainsString('renommez', $tester->getDisplay());
    }

    /** Un ancien marque.yaml passe la vérification, mais la commande demande de le renommer. */
    #[IgnoreDeprecations('marque\\.yaml')]
    public function testUnAncienMarqueYamlEstValableMaisSignale(): void
    {
        file_put_contents($this->tmp.'/marque.yaml', "name: Atelier Bigorneau\n");
        $tester = $this->tester($this->tmp);
        $this->expectUserDeprecationMessageMatches('/renommez ce fichier en « theme\\.yaml »/');

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        // Un avertissement de SymfonyStyle préfixe chaque ligne coupée : on cherche des mots, pas une phrase.
        $this->assertStringContainsString('renommez', $tester->getDisplay());
        $this->assertStringContainsString('Atelier Bigorneau', $tester->getDisplay());
    }

    /** Les fichiers que le thème apporte sont listés : on voit d'un coup d'œil ce qu'il charge. */
    public function testLesFichiersDuThemeSontListes(): void
    {
        (new Filesystem())->dumpFile($this->tmp.'/assets/theme.css', 'body{}');
        file_put_contents($this->tmp.'/theme.yaml', "name: A\nstylesheets: [assets/theme.css]\nreplaces_engine_styles: true\n");
        $tester = $this->tester($this->tmp);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('remplacent celle du moteur', $tester->getDisplay());
        $this->assertStringContainsString('assets/theme.css', $tester->getDisplay());
    }

    /** L'ancien nom de la commande, encore cité par des scripts de déploiement, reste un alias jusqu'à la 4.0. */
    public function testLAncienNomDeLaCommandeEstUnAlias(): void
    {
        $command = new Command(null, new ThemeCheckCommand(self::theme('')));

        $this->assertSame('app:theme:verifier', $command->getName());
        $this->assertContains('app:marque:verifier', $command->getAliases());
    }
}
