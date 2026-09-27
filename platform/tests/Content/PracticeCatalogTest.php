<?php

namespace App\Tests\Content;

use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\Practice;
use App\Content\PracticeCatalog;
use App\Content\PracticeFilter;
use App\Content\PracticeListing;
use App\Content\PracticeVisibility;
use App\Entity\ExerciseProgress;
use App\Entity\User;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;

/**
 * La Pratique du pack de test : « Côté Laravel » (20/08, Laravel 13.0, Eloquent), « Un point précis » (01/09,
 * Validator…), « Une nouveauté récente » (10/09, Symfony 8.1, Routing), et pour un administrateur « Pas encore
 * publié » (15/09, en préparation) et « Publié plus tard » (2099).
 */
final class PracticeCatalogTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    private function catalog(string $today = '2026-09-12', bool $admin = false): PracticeCatalog
    {
        $content = new ContentRepository([__DIR__.'/../Fixtures/packs/pratique'], new EnvironmentRegistry(self::ROOT.'/environments'), new Version(self::ROOT.'/VERSION'));
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($admin);

        $clock = new MockClock($today.' 15:00');

        return new PracticeCatalog(new PracticeVisibility($content, $security, $clock), $clock);
    }

    /** @return list<string> */
    private static function titles(PracticeListing $listing): array
    {
        return array_merge(...array_map(static fn (array $group) => array_map(static fn (array $item) => $item['practice']->exercise->title, $group['items']), $listing->groups));
    }

    /** @return list<string> */
    private static function labels(PracticeListing $listing): array
    {
        return array_column($listing->groups, 'label');
    }

    public function testLeFiltreNormaliseLaRequete(): void
    {
        $filter = new PracticeFilter('', ['Validator', 'Validator', ['x']], true, '  mail ', 'inconnu');

        $this->assertSame(['framework' => null, 'notions' => ['Validator'], 'nouveautes' => true, 'recherche' => 'mail', 'tri' => 'recent'], $filter->toArray());
        $this->assertSame(['Twig'], (new PracticeFilter(notions: 'Twig'))->notions, 'Une seule notion, sans crochets.');
    }

    public function testSansFiltreDuPlusRecentAuPlusAncien(): void
    {
        $listing = $this->catalog()->search(new PracticeFilter(), []);

        $this->assertSame(['Une nouveauté récente', 'Un point précis', 'Côté Laravel'], self::titles($listing));
        $this->assertSame(3, $listing->shown);
        $this->assertCount(3, $listing->all);
        $this->assertSame(2, $listing->newCount);
    }

    public function testLesFiltresSeCumulent(): void
    {
        $catalog = $this->catalog();

        $this->assertSame(['Côté Laravel'], self::titles($catalog->search(new PracticeFilter('laravel'), [])));
        $this->assertSame(['Une nouveauté récente', 'Côté Laravel'], self::titles($catalog->search(new PracticeFilter(nouveautes: true), [])));
        $this->assertSame(['Côté Laravel', 'Un point précis'], self::titles($catalog->search(new PracticeFilter(notions: ['Validator', 'Eloquent'], tri: 'titre'), [])));
        $this->assertSame(['Un point précis'], self::titles($catalog->search(new PracticeFilter(recherche: 'VALIDATOR'), [])), 'Les notions, sans la casse.');
        $this->assertSame([], self::titles($catalog->search(new PracticeFilter('laravel', recherche: 'validator'), [])));
    }

    public function testLesNotionsSeComptentSurLeFrameworkChoisi(): void
    {
        $listing = $this->catalog()->search(new PracticeFilter('laravel', ['Eloquent', 'Validator']), []);

        $this->assertSame(['Eloquent' => 1], $listing->notionCounts);
        $this->assertSame(['Eloquent'], $listing->filter->notions, 'Une notion que ce framework n\'a pas est oubliée.');
        $this->assertSame(['Côté Laravel'], self::titles($listing));
    }

    public function testLaSemaineSeCompteDepuisLHorloge(): void
    {
        $this->assertSame(['Cette semaine', 'Avant'], self::labels($this->catalog('2026-09-12')->search(new PracticeFilter(), [])), 'Le 10/09 est de la semaine.');
        $this->assertSame(['Avant'], self::labels($this->catalog('2026-09-17')->search(new PracticeFilter(), [])), 'Le 10/09 n\'en est plus.');
        $this->assertSame(['Cette semaine', 'Avant'], self::labels($this->catalog('2026-09-16')->search(new PracticeFilter(), [])), 'Sept jours, aujourd\'hui compris.');
        $this->assertSame(['Par titre'], self::labels($this->catalog()->search(new PracticeFilter(tri: 'titre'), [])));
    }

    public function testUnAdministrateurVoitCeQuiEstAVenir(): void
    {
        $listing = $this->catalog('2026-09-12', admin: true)->search(new PracticeFilter(), []);

        $this->assertSame(['À venir', 'Cette semaine', 'Avant'], self::labels($listing));
        $this->assertSame(['Publié plus tard', 'Pas encore publié'], array_map(static fn (array $item) => $item['practice']->exercise->title, $listing->groups[0]['items']));
    }

    public function testLEtatDeChaqueExerciceVientDeLaProgression(): void
    {
        $practices = array_values(array_map(static fn (Practice $p) => $p->exercise->id, $this->catalog()->search(new PracticeFilter(), [])->all));
        $done = new ExerciseProgress(new User(), null, $practices[0]);
        $done->complete(0, 0, new \DateTimeImmutable());

        $listing = $this->catalog()->search(new PracticeFilter(), [$practices[0] => $done]);

        $this->assertSame('completed', $listing->groups[0]['items'][0]['state']);
        $this->assertSame('todo', $listing->groups[1]['items'][0]['state']);
        $this->assertSame(1, $listing->completedCount);
    }

    public function testUnExerciceParaitLeJourQueDitLHorloge(): void
    {
        $this->assertNotContains('Publié plus tard', self::titles($this->catalog('2098-12-31')->search(new PracticeFilter(), [])));
        $this->assertContains('Publié plus tard', self::titles($this->catalog('2099-01-01')->search(new PracticeFilter(), [])), 'Le jour même, il paraît.');
    }
}
