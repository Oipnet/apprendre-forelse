<?php

namespace App\Tests\Seo;

use App\Content\PracticeFilter;
use App\Seo\Page\PracticeSeo;
use App\Seo\PageSeo;
use App\Tests\PacksTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Les hreflang de la liste de Pratique découpée en pages : la page 2 française et la page 2 anglaise se nomment
 * l'une l'autre (une annotation sans retour est ignorée des moteurs).
 */
final class PracticeSeoTest extends KernelTestCase
{
    use PacksTrait;

    protected function setUp(): void
    {
        $this->usePacks(__DIR__.'/../../../examples/packs');
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    public function testChaquePageAnnonceLaMemePageDansLAutreLangue(): void
    {
        $this->assertSame([
            'en' => 'http://localhost/en/practice?page=2',
            'fr' => 'http://localhost/pratique?page=2',
            'x-default' => 'http://localhost/pratique?page=2',
        ], $this->alternates('fr', new PracticeFilter(page: 2), ['fr' => 3, 'en' => 2]));

        $this->assertSame([
            'en' => 'http://localhost/en/practice?page=2',
            'fr' => 'http://localhost/pratique?page=2',
            'x-default' => 'http://localhost/pratique?page=2',
        ], $this->alternates('en', new PracticeFilter(page: 2), ['fr' => 3, 'en' => 2]), 'La page anglaise nomme la française en retour.');
    }

    public function testUneLangueQuiNAPasCettePageNEstPasAnnoncee(): void
    {
        $this->assertSame([], $this->alternates('fr', new PracticeFilter(page: 3), ['fr' => 3, 'en' => 2]));
        $this->assertSame([], $this->alternates('fr', new PracticeFilter(), ['fr' => 3, 'en' => 0]), 'Pas d\'exercice traduit : pas de liste anglaise.');
    }

    /**
     * @param array<string, int> $pageCounts
     *
     * @return array<string, string>
     */
    private function alternates(string $locale, PracticeFilter $filter, array $pageCounts): array
    {
        $request = Request::create('/');
        $request->setLocale($locale);
        $requests = static::getContainer()->get(RequestStack::class);
        $requests->push($request);
        $seo = static::getContainer()->get(PageSeo::class);
        $seo->reset();
        try {
            // La langue du routeur aussi : le canonical se génère dans la langue de la page.
            return static::getContainer()->get(LocaleSwitcher::class)->runWithLocale($locale, static function () use ($filter, $pageCounts, $seo): array {
                static::getContainer()->get(PracticeSeo::class)->index([], $filter, $pageCounts);

                return $seo->getAlternates();
            });
        } finally {
            $requests->pop();
        }
    }
}
