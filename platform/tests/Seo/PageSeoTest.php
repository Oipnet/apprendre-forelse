<?php

namespace App\Tests\Seo;

use App\Security\SandboxOrigin;
use App\Seo\PageSeo;
use App\Seo\SearchIndexing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class PageSeoTest extends TestCase
{
    private function seo(): PageSeo
    {
        return new PageSeo(new RequestStack(), new SearchIndexing(new SandboxOrigin('http://127.0.0.1:8001', 'http://localhost'), true));
    }

    public function testLeTitreRetenuEstLePremierQuiTient(): void
    {
        $seo = $this->seo()->setTitle(str_repeat('a', 61), 'Formation Symfony en ligne | Forelse', 'Symfony');

        $this->assertSame('Formation Symfony en ligne | Forelse', $seo->getTitle());
    }

    public function testUnTitreTropLongEstCoupeSurUnMot(): void
    {
        $title = $this->seo()->setTitle('Un titre beaucoup trop long pour tenir dans les soixante caractères permis')->getTitle();

        $this->assertLessThanOrEqual(PageSeo::TITLE_MAX, mb_strlen((string) $title));
        $this->assertSame('Un titre beaucoup trop long pour tenir dans les soixante…', $title);
    }

    public function testUneDescriptionCourteEstCompleteeSiLeComplementTient(): void
    {
        $seo = $this->seo()->setDescription('Un résumé court.', 'Un complément utile.');
        $this->assertSame('Un résumé court. Un complément utile.', $seo->getDescription());

        $long = str_repeat('mot ', 35);
        $seo->setDescription($long, 'Un complément utile.');
        $this->assertStringNotContainsString('complément', (string) $seo->getDescription());
    }

    public function testUneDescriptionTropLongeNeDepassePas155Caracteres(): void
    {
        $description = $this->seo()->setDescription(str_repeat('développer, ', 30))->getDescription();

        $this->assertLessThanOrEqual(PageSeo::DESCRIPTION_MAX, mb_strlen((string) $description));
        $this->assertStringEndsWith('développer…', (string) $description);
    }
}
