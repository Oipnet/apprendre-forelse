<?php

namespace App\Tests\Cohort;

use App\Cohort\CohortAccessSync;
use App\Cohort\CohortManagement;
use App\Cohort\CohortRuleViolation;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Entity\Cohort;
use App\Entity\FundingMode;
use App\Repository\CohortRepository;
use App\Repository\TrackAccessRepository;
use App\Repository\UserRepository;
use App\Version;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Les règles du chef de cohorte sur les parcours proposés (catalogue : laravel-bases, symfony-bases, atelier-secret en préparation). */
final class CohortManagementTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    private ContentRepository $content;
    private int $transactions = 0;

    private function management(): CohortManagement
    {
        $this->content = new ContentRepository([__DIR__.'/../Fixtures/packs/cohortes'], new EnvironmentRegistry(self::ROOT.'/environments'), new Version(self::ROOT.'/VERSION'));
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(function (callable $callback) {
            ++$this->transactions;

            return $callback();
        });
        $sync = new CohortAccessSync($this->createStub(TrackAccessRepository::class), $this->createStub(UserRepository::class), $entityManager, new MockClock());

        return new CohortManagement($entityManager, $sync, $this->createStub(CohortRepository::class), $this->content);
    }

    /** @param list<string> $ids */
    private function inCatalogueOrder(array $ids): array
    {
        return array_values(array_filter(array_keys($this->content->tracks()), static fn (string $id) => \in_array($id, $ids, true)));
    }

    public function testLaSelectionSuitLOrdreDuCatalogue(): void
    {
        $management = $this->management();
        $cohort = new Cohort();

        $management->chooseTracks($cohort, ['symfony-bases', 'laravel-bases'], true);

        $this->assertSame($this->inCatalogueOrder(['symfony-bases', 'laravel-bases']), $cohort->getAvailableTrackIds());
        $this->assertSame(1, $this->transactions, 'La sélection et ses accès dans une transaction.');
    }

    public function testUnChefNeCocheNiNeDecocheUnParcoursEnPreparation(): void
    {
        $management = $this->management();
        $avec = (new Cohort())->setAvailableTrackIds(['atelier-secret', 'symfony-bases']);
        $sans = (new Cohort())->setAvailableTrackIds(['symfony-bases']);

        $management->chooseTracks($avec, ['laravel-bases'], false);
        $management->chooseTracks($sans, ['symfony-bases', 'atelier-secret'], false);

        $this->assertSame($this->inCatalogueOrder(['laravel-bases', 'atelier-secret']), $avec->getAvailableTrackIds(), 'Conservé, décoché ou non.');
        $this->assertSame(['symfony-bases'], $sans->getAvailableTrackIds(), 'Coché par le chef : ignoré.');
    }

    public function testUnAdministrateurChoisitAussiLesParcoursEnPreparation(): void
    {
        $management = $this->management();
        $cohort = (new Cohort())->setAvailableTrackIds(['atelier-secret']);

        $management->chooseTracks($cohort, ['symfony-bases'], true);

        $this->assertSame(['symfony-bases'], $cohort->getAvailableTrackIds());
        $this->assertSame([], $management->lockedTrackIds(true));
        $this->assertSame(['atelier-secret'], $management->lockedTrackIds(false));
    }

    public function testUneCohorteFinanceeParLEtablissementGardeAuMoinsUnParcours(): void
    {
        $management = $this->management();
        $cohort = (new Cohort())->setFundingMode(FundingMode::Institution)->setAvailableTrackIds(['symfony-bases']);

        try {
            $management->chooseTracks($cohort, [], false);
            $this->fail('Refusé.');
        } catch (CohortRuleViolation $e) {
            $this->assertStringContainsString('au moins un parcours', $e->getMessage());
        }
        $this->assertSame(['symfony-bases'], $cohort->getAvailableTrackIds(), 'Rien n\'a été enregistré.');
        $this->assertSame(0, $this->transactions);
    }

    public function testSansSelectionUneCohorteDApprenantsProposeTousLesParcours(): void
    {
        $management = $this->management();
        $cohort = (new Cohort())->setFundingMode(FundingMode::Learners)->setAvailableTrackIds(['symfony-bases']);

        $management->chooseTracks($cohort, [], false);

        $this->assertFalse($cohort->hasTrackSelection());
    }
}
