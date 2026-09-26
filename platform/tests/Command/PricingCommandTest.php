<?php

namespace App\Tests\Command;

use App\Entity\TrackPricing;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** app:tarif : fixe le prix d'un parcours (pack de test « payant »), en euros saisis comme on les écrit. */
final class PricingCommandTest extends KernelTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    protected function setUp(): void
    {
        $this->usePaidPack();
        self::bootKernel();
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    /** @param array<string, mixed> $input */
    private function console(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:tarif'));
        $tester->execute($input);

        return $tester;
    }

    /** @return list<TrackPricing> */
    private function pricings(): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(TrackPricing::class)->findAll();
    }

    public function testFixeLePrixNormalEtLePrixFondateur(): void
    {
        $tester = $this->console(['parcours' => 'payant', 'prix' => '79', '--fondateur' => '49,90', '--quota' => 100, '--fin' => '2026-12-31']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('« payant » : 79,00 € TTC, fondateur 49,90 €', $tester->getDisplay(true));
        [$pricing] = $this->pricings();
        $this->assertSame(7900, $pricing->getNormalPrice());
        $this->assertSame(4990, $pricing->getFounderPrice());
        $this->assertTrue($pricing->isFounderActive());
        $this->assertSame(100, $pricing->getFounderQuotaMax());
        $this->assertSame('2026-12-31', $pricing->getFounderEndsAt()?->format('Y-m-d'));
    }

    public function testUnSecondPassageModifieLeMemeTarif(): void
    {
        $this->console(['parcours' => 'payant', 'prix' => '79', '--fondateur' => '49']);
        // Un autre lancement, un autre processus : le dépôt garde les tarifs lus en mémoire le temps d'une exécution.
        self::ensureKernelShutdown();
        self::bootKernel();

        $tester = $this->console(['parcours' => 'payant', 'prix' => '89.5', '--sans-fondateur' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $pricings = $this->pricings();
        $this->assertCount(1, $pricings, 'Le tarif existant est repris, pas dupliqué.');
        $this->assertSame(8950, $pricings[0]->getNormalPrice());
        $this->assertFalse($pricings[0]->isFounderActive());
        $this->assertStringNotContainsString('fondateur', $tester->getDisplay(true));
    }

    public function testLesSaisiesInvalidesSontRefuseesSansRienEnregistrer(): void
    {
        $inconnu = $this->console(['parcours' => 'nulle-part', 'prix' => '79']);
        $this->assertSame(Command::FAILURE, $inconnu->getStatusCode());
        $this->assertStringContainsString('Parcours « nulle-part » inconnu (installés : payant)', $inconnu->getDisplay(true));

        $illisible = $this->console(['parcours' => 'payant', 'prix' => '79 €']);
        $this->assertSame(Command::FAILURE, $illisible->getStatusCode());
        $this->assertStringContainsString('Montant illisible', $illisible->getDisplay(true));

        $fondateurTropCher = $this->console(['parcours' => 'payant', 'prix' => '49', '--fondateur' => '79']);
        $this->assertSame(Command::FAILURE, $fondateurTropCher->getStatusCode());
        $this->assertStringContainsString('Le prix fondateur doit être inférieur au prix normal', $fondateurTropCher->getDisplay(true));

        $this->assertSame([], $this->pricings());
    }
}
