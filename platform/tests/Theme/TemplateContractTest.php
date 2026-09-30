<?php

namespace App\Tests\Theme;

use App\Theme\TemplateContract;
use PHPUnit\Framework\TestCase;

/** Le contrat des gabarits reste écrit : chaque point de surcharge existe et dit, en tête, ses variables. */
final class TemplateContractTest extends TestCase
{
    private const string TEMPLATES = __DIR__.'/../../templates/';

    public function testChaquePointDeSurchargeExisteEtPorteSonEnTeteTheme(): void
    {
        foreach (TemplateContract::POINTS as $name) {
            $this->assertFileExists(self::TEMPLATES.$name);
            $this->assertMatchesRegularExpression('/\{#.*@theme.*#\}/s', (string) file_get_contents(self::TEMPLATES.$name), $name.' : son en-tête « @theme » documente ce qu\'un thème peut en attendre.');
        }
    }

    public function testLesGabaritsVerrouillesExistent(): void
    {
        foreach (TemplateContract::LOCKED as $name) {
            $this->assertFileExists(self::TEMPLATES.$name);
            $this->assertNotContains($name, TemplateContract::POINTS, $name.' ne peut pas être à la fois verrouillé et point de surcharge.');
        }
    }

    public function testUnGabaritVerrouilleSeReconnaitSousToutesSesFormes(): void
    {
        $this->assertTrue(TemplateContract::isLocked('exercise/play.html.twig'));
        $this->assertTrue(TemplateContract::isLocked('/studio/edit.html.twig'));
        $this->assertTrue(TemplateContract::isLocked('@__main__/studio/lesson.html.twig'));
        $this->assertFalse(TemplateContract::isLocked('exercise/show.html.twig'), 'La présentation de l\'exercice est une page du site.');
    }
}
