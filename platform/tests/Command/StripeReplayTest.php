<?php

namespace App\Tests\Command;

use App\Entity\PriceKind;
use App\Entity\Purchase;
use App\Entity\PurchaseStatus;
use App\Entity\StripeEvent;
use App\Payment\WithdrawalWaiver;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use App\Tests\Payment\FakePaymentGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** app:stripe:rejouer : chaque événement est traité à part, un échec n'arrête pas les suivants. */
final class StripeReplayTest extends KernelTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    protected function setUp(): void
    {
        $this->usePaidPack();
        FakePaymentGateway::reset();
        self::bootKernel();
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testUnEvenementEnEchecNEmpechePasLesSuivants(): void
    {
        $entityManager = $this->entityManager();
        $user = $this->createUser();
        foreach (['cs_ok_1', 'cs_ok_2'] as $session) {
            $purchase = new Purchase($user, 'payant', 7900, PriceKind::Normal, WithdrawalWaiver::TEXT, new \DateTimeImmutable());
            $purchase->attachCheckoutSession($session);
            $entityManager->persist($purchase);
        }
        // Reçus dans cet ordre : le premier vise une session sans achat.
        foreach (['evt_inconnu' => 'cs_inconnue', 'evt_ok_1' => 'cs_ok_1', 'evt_ok_2' => 'cs_ok_2'] as $eventId => $session) {
            $payload = json_encode(['id' => $eventId, 'type' => 'checkout.session.completed', 'data' => ['object' => [
                'id' => $session, 'payment_status' => 'paid', 'amount_total' => 7900, 'payment_intent' => 'pi_'.$session,
            ]]], \JSON_THROW_ON_ERROR);
            $entityManager->persist(new StripeEvent($eventId, 'checkout.session.completed', $payload, new \DateTimeImmutable()));
            $entityManager->flush();
        }
        $entityManager->clear();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:stripe:rejouer'));
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode(), 'Un événement reste en échec.');
        $display = $tester->getDisplay(true);
        $this->assertStringContainsString('✘ evt_inconnu', $display);
        $this->assertStringContainsString('✔ evt_ok_1', $display);
        $this->assertStringContainsString('✔ evt_ok_2', $display);

        $entityManager = $this->entityManager();
        $entityManager->clear();
        $events = $entityManager->getRepository(StripeEvent::class)->findBy([], ['id' => 'ASC']);
        $this->assertFalse($events[0]->isProcessed());
        $this->assertStringContainsString('Aucun achat pour la session Stripe « cs_inconnue »', (string) $events[0]->getError());
        $this->assertTrue($events[1]->isProcessed());
        $this->assertTrue($events[2]->isProcessed());
        $statuses = array_map(static fn (Purchase $p) => $p->getStatus(), $entityManager->getRepository(Purchase::class)->findAll());
        $this->assertSame([PurchaseStatus::Paid, PurchaseStatus::Paid], $statuses);
    }
}
