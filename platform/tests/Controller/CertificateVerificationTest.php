<?php

namespace App\Tests\Controller;

use App\Certificate\CertificateRevocation;
use App\Entity\VerificationStatus;
use App\Repository\CertificateRepository;
use App\Repository\ExerciseProgressRepository;
use App\Content\ContentRepository;
use App\Service\ProgressService;
use App\Tests\DatabaseTrait;
use App\Verification\CollectVerifications;
use App\Verification\VerificationDispatcher;
use App\Verification\VerificationQueue;
use App\Verification\VerificationResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/** Une réussite confirmée puis infirmée par le verificateur (nouvelle vérification) fait révoquer le certificat du parcours qui la comptait. */
final class CertificateVerificationTest extends WebTestCase
{
    use DatabaseTrait;

    public function testUneReussiteInfirmeeRevoqueLeCertificat(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $container = static::getContainer();
        $content = $container->get(ContentRepository::class);
        $progress = $container->get(ProgressService::class);
        $user = $this->createUser();
        foreach (['01-bonjour', '02-bonjour-prenom'] as $exerciseId) {
            $exercise = $content->findExercise('decouverte', $exerciseId);
            $progress->saveDraft($user, $exercise, [$exercise->editablePaths()[0] => '<?php // code'], 0);
            $progress->complete($user, $exercise, 0);
            // Confirmée une première fois : le certificat attend ce verdict pour être délivré.
            $container->get(ExerciseProgressRepository::class)->findOne($user, 'decouverte', $exerciseId)?->recordVerification(VerificationStatus::Passed, null, new \DateTimeImmutable());
        }
        $container->get(EntityManagerInterface::class)->flush();
        $client->loginUser($user);
        $client->request('GET', '/parcours/decouverte/certificat');
        $client->submitForm('Obtenir mon certificat', ['certificate_claim[holderName]' => 'Ada Lovelace'], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);

        $directory = sys_get_temp_dir().'/verifications-'.bin2hex(random_bytes(4));
        $queue = new VerificationQueue($directory);
        $second = $container->get(ExerciseProgressRepository::class)->findOne($user, 'decouverte', '02-bonjour-prenom');
        $queue->pushResult(new VerificationResult((int) $second->getId(), VerificationStatus::Failed, 'objectif manqué'));
        $dispatcher = new VerificationDispatcher($queue, $container->get(ExerciseProgressRepository::class), $container->get(EntityManagerInterface::class), $container->get('clock'), $container->get(CertificateRevocation::class), $content);
        $dispatcher->collect(new CollectVerifications());
        (new Filesystem())->remove($directory);

        $certificate = $container->get(CertificateRepository::class)->findOneBy([]);
        $this->assertTrue($certificate?->isRevoked());
        $this->assertEmailCount(1);
        $this->assertEmailTextBodyContains($this->getMailerMessage(), 'n\'a pas été confirmée');
    }
}
