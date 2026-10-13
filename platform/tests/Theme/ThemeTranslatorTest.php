<?php

namespace App\Tests\Theme;

use App\Theme\FixedTheme;
use App\Theme\ThemeTranslations;
use App\Theme\ThemeTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Translation\Formatter\MessageFormatter;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/** Les textes d'un thème (translations/theme.<langue>.yaml), servis par le traducteur du site dans le domaine « theme ». */
final class ThemeTranslatorTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/theme-trad-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->tmp.'/translations/theme.fr.yaml', <<<'YAML'
            hero:
                titre: "Apprendre {sujet} en codant"
                exercices: "{n, plural, one {# exercice} other {# exercices}}"
            seulement_fr: Questions fréquentes
            YAML);
        $filesystem->dumpFile($this->tmp.'/translations/theme.en.yaml', <<<'YAML'
            hero:
                titre: "Learn {sujet} by coding"
                exercices: "{n, plural, one {# exercise} other {# exercises}}"
            YAML);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    private function translator(string $directory, string $locale = 'fr'): ThemeTranslator
    {
        $inner = new Translator($locale, new MessageFormatter());
        $inner->addLoader('array', new ArrayLoader());
        $inner->addResource('array', ['header.blog' => 'Le blog'], 'fr', 'messages');

        return new ThemeTranslator($inner, new FixedTheme($directory), 'fr');
    }

    public function testLesTextesDuThemeSuiventLaLangueDeLaPage(): void
    {
        $translator = $this->translator($this->tmp);

        $this->assertSame('Apprendre Symfony en codant', $translator->trans('hero.titre', ['sujet' => 'Symfony'], 'theme'));
        $this->assertSame('3 exercices', $translator->trans('hero.exercices', ['n' => 3], 'theme'));

        $translator->setLocale('en');
        $this->assertSame('Learn Symfony by coding', $translator->trans('hero.titre', ['sujet' => 'Symfony'], 'theme'));
        $this->assertSame('1 exercise', $translator->trans('hero.exercices', ['n' => 1], 'theme'));
        $this->assertSame('Questions fréquentes', $translator->trans('seulement_fr', [], 'theme'), 'Une clé absente en anglais retombe sur le français.');
        $this->assertSame('inconnue', $translator->trans('inconnue', [], 'theme'), 'Une clé inconnue s\'affiche telle quelle, comme |trans.');
    }

    public function testLesAutresDomainesRestentCeuxDuMoteur(): void
    {
        $this->assertSame('Le blog', $this->translator($this->tmp)->trans('header.blog'));
        $this->assertSame('hero.titre', $this->translator('')->trans('hero.titre', [], 'theme'), 'Sans thème, rien dans le domaine.');
    }

    public function testUnFichierModifieSeRelit(): void
    {
        $translator = $this->translator($this->tmp);
        $this->assertSame('Questions fréquentes', $translator->trans('seulement_fr', [], 'theme'));

        file_put_contents($this->tmp.'/translations/theme.fr.yaml', "seulement_fr: FAQ du thème\n");
        touch($this->tmp.'/translations/theme.fr.yaml', time() + 10);
        clearstatcache();
        $this->assertSame('FAQ du thème', $translator->trans('seulement_fr', [], 'theme'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function fichiersRefuses(): iterable
    {
        yield 'YAML invalide' => ["a: [\n", 'YAML invalide'];
        yield 'valeur qui n\'est pas un texte' => ["a: ~\n", '« a » doit être un texte'];
        yield 'message ICU mal formé' => ["a: \"{n, plural, one {x}\"\n", '« a » n\'est pas un message ICU valable'];
    }

    #[DataProvider('fichiersRefuses')]
    public function testUnFichierMalFormeEstRefuse(string $yaml, string $message): void
    {
        file_put_contents($this->tmp.'/translations/theme.fr.yaml', $yaml);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        (new ThemeTranslations())->load($this->tmp);
    }
}
