<?php

namespace App\Tests\Admin;

use App\Admin\BetaStats;
use App\Admin\CohortStats;
use App\Admin\TrackStats;
use App\Entity\ExerciseProgress;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Chiffres des tableaux de bord (/admin, /cohorte/{id}, fiche d'un apprenant). Ils ne montrent que l'état de la
 * progression : les brouillons de code (colonne files) ne sont jamais lus. Pack de test « cohortes ».
 */
final class BetaStatsTest extends KernelTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    protected function setUp(): void
    {
        $this->usePacks(__DIR__.'/../Fixtures/packs/cohortes');
        self::bootKernel();
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    public function testLesChiffresNeLisentPasLesBrouillonsDeCode(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->createCohort('iut')->setAvailableTrackIds(['symfony-bases', 'laravel-bases']);
        $ada = $this->createUser('ada@example.test', 'Ada', 'iut');
        $bob = $this->createUser('bob@example.test', 'Bob', 'iut');
        // Ada a réussi le parcours Symfony ; Bob est en cours sur Laravel, avec deux indices et un gros brouillon.
        $done = new ExerciseProgress($ada, 'symfony-bases', 'e1');
        $done->complete(0, 10);
        $draft = new ExerciseProgress($bob, 'laravel-bases', 'e1');
        $draft->saveDraft(['src/Kernel.php' => str_repeat('<?php // brouillon', 1000)], 2);
        $entityManager->persist($done);
        $entityManager->persist($draft);
        $entityManager->flush();
        $entityManager->clear();

        $stats = static::getContainer()->get(BetaStats::class);
        $holder = static::getContainer()->get('test.doctrine.debug_data_holder');
        $holder->reset();

        $iut = $stats->cohort('iut');
        $overview = $stats->cohorts();
        $inProgress = $stats->learnersInProgress($iut->cohort);
        $learner = $stats->learner($bob);

        $queries = array_column($holder->getData()['default'] ?? [], 'sql');
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\bfiles\b/', $sql, 'Aucune requête ne lit les brouillons de code.');
        }

        $this->assertNotNull($iut);
        $this->assertSame(1, $iut->completedExercises());
        $this->assertSame(1, $overview[0]->completedExercises());
        $this->assertSame(1, $this->track($iut, 'symfony-bases')->completedBy((int) $ada->getId()));
        $bobs = $this->track($learner, 'laravel-bases')->progressOf((int) $bob->getId(), 'e1');
        $this->assertNotNull($bobs);
        $this->assertFalse($bobs->isCompleted());
        $this->assertSame(2, $bobs->hintsUsed);
        $this->assertSame(['laravel-bases' => 1], $inProgress, 'Bob a commencé Laravel sans le finir.');
    }

    private function track(CohortStats $stats, string $trackId): TrackStats
    {
        foreach ($stats->tracks as $track) {
            if ($track->id === $trackId) {
                return $track;
            }
        }
        $this->fail(sprintf('Parcours « %s » absent des chiffres.', $trackId));
    }
}
