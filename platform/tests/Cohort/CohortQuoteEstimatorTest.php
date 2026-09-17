<?php

namespace App\Tests\Cohort;

use App\Cohort\CohortQuoteEstimator;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Entity\Cohort;
use App\Version;
use PHPUnit\Framework\TestCase;

final class CohortQuoteEstimatorTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    private function estimator(): CohortQuoteEstimator
    {
        // Paliers volontairement dans le désordre : l'estimateur les trie.
        return new CohortQuoteEstimator(
            new ContentRepository([__DIR__.'/../Fixtures/packs/cohortes'], new EnvironmentRegistry(self::ROOT.'/environments'), new Version(self::ROOT.'/VERSION')),
            3000,
            [['from' => 30, 'percent' => 50], ['from' => 1, 'percent' => 100], ['from' => 10, 'percent' => 70]],
        );
    }

    public function testEffectifFoisParcoursFoisPrixUnitaireSelonLePalier(): void
    {
        $estimator = $this->estimator();

        $this->assertSame(9 * 2 * 3000, $estimator->compute(9, 2)->amount, 'Moins de 10 : plein tarif.');
        $tenth = $estimator->compute(10, 2);
        $this->assertSame(70, $tenth->percent);
        $this->assertSame(42000, $tenth->amount, '10 × 2 × 30 € × 70 %.');
        $this->assertSame(45000, $estimator->compute(30, 1)->amount, '30 × 1 × 30 € × 50 %.');
        $this->assertSame(0, $estimator->compute(0, 3)->amount, 'Effectif inconnu : estimation nulle.');
    }

    public function testLesParcoursComptesSontCeuxDeLaCohorte(): void
    {
        $cohort = (new Cohort())->setAvailableTrackIds(['symfony-bases', 'atelier-secret'])->setExpectedHeadcount(12);
        $quote = $this->estimator()->estimate($cohort);

        $this->assertSame(2, $quote->trackCount);
        $this->assertSame(50400, $quote->amount);
        $this->assertTrue($quote->differsFrom(40000), 'Un devis différent est à revoir.');
        $this->assertFalse($quote->differsFrom(null), 'Pas de devis : rien à revoir.');

        $this->assertSame(2, $this->estimator()->estimate(new Cohort())->trackCount, 'Sans sélection : les parcours publics (pas celui en préparation).');
    }
}
