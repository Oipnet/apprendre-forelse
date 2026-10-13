<?php

namespace App\Tests\Controller;

use App\Certificate\CertificateRevocation;
use App\Content\ContentRepository;
use App\Entity\Certificate;
use App\Entity\ContactMessage;
use App\Entity\ContactSubject;
use App\Entity\ExerciseProgress;
use App\Entity\User;
use App\Entity\VerificationStatus;
use App\Repository\CertificateRepository;
use App\Service\ProgressService;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Le certificat de réussite du parcours « decouverte » du pack de démo (deux exercices). */
final class CertificateTest extends WebTestCase
{
    use DatabaseTrait;

    private const array ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Le premier exercice avec un indice, le second en consultant la solution. */
    private function completeTrack(User $user): void
    {
        $content = static::getContainer()->get(ContentRepository::class);
        $progress = static::getContainer()->get(ProgressService::class);
        $user = $this->entityManager()->find(User::class, $user->getId()) ?? $user;
        $progress->complete($user, $content->findExercise('decouverte', '01-bonjour'), 1);
        $second = $content->findExercise('decouverte', '02-bonjour-prenom');
        $progress->revealSolution($user, $second);
        $progress->complete($user, $second, 0);
    }

    private function verify(User $user, string $exerciseId, VerificationStatus $status): void
    {
        $progress = $this->entityManager()->getRepository(ExerciseProgress::class)->findOneBy(['user' => $user->getId(), 'trackId' => 'decouverte', 'exerciseId' => $exerciseId]) ?? self::fail('Exercice non commencé.');
        $progress->recordVerification($status, null, new \DateTimeImmutable());
        $this->entityManager()->flush();
    }

    private function claim(User $user, string $name = 'Ada Lovelace'): Certificate
    {
        $this->client->loginUser($user);
        $this->client->request('GET', '/parcours/decouverte/certificat');
        $this->client->submitForm('Obtenir mon certificat', ['certificate_claim[holderName]' => $name], serverParameters: self::ORIGIN);
        $this->entityManager()->clear();

        return static::getContainer()->get(CertificateRepository::class)->findOneBy([]) ?? self::fail('Aucun certificat délivré.');
    }

    public function testUnInviteEstInviteASeConnecter(): void
    {
        $this->client->request('GET', '/parcours/decouverte/certificat');

        $this->assertResponseRedirects('/connexion');
    }

    public function testPasDeCertificatAvantLaFinDuParcours(): void
    {
        $user = $this->createUser();
        $content = static::getContainer()->get(ContentRepository::class);
        static::getContainer()->get(ProgressService::class)->complete($user, $content->findExercise('decouverte', '01-bonjour'), 0);
        $this->client->loginUser($user);

        $this->client->request('GET', '/parcours/decouverte');
        $this->assertSelectorNotExists('.track-certificate');

        $this->client->request('GET', '/parcours/decouverte/certificat');
        $this->assertResponseRedirects('/parcours/decouverte');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash', 'tous les exercices du parcours réussis');
    }

    public function testLeParcoursTermineDelivreUnCertificatAuNomConfirme(): void
    {
        $user = $this->createUser();
        $this->completeTrack($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/parcours/decouverte');
        $this->assertSame('/parcours/decouverte/certificat', $crawler->filter('.track-done a.track-certificate')->attr('href'));

        $crawler = $this->client->request('GET', '/parcours/decouverte/certificat');
        $this->assertResponseIsSuccessful();
        $this->assertSame('Ada', $crawler->filter('#certificate_claim_holderName')->attr('value'), 'Le nom du compte est proposé.');

        $certificate = $this->claim($user);
        $this->assertResponseRedirects('/certificat/'.$certificate->getCode(), 303);
        $this->assertMatchesRegularExpression('/^[2-9A-HJ-NP-TW-Z]{4}-[2-9A-HJ-NP-TW-Z]{4}$/', $certificate->getCode());
        $this->assertSame('Ada Lovelace', $certificate->getHolderName());
        $this->assertSame('Découverte', $certificate->getTrackTitle());

        $this->client->followRedirect();
        $this->assertSelectorTextContains('h1', 'Découverte');
        $this->assertSelectorTextContains('main', 'Délivré à Ada Lovelace');
        $this->assertSelectorTextContains('.certificate-status', 'Certificat valide');
        $this->assertSelectorTextContains('.certificate-summary', '2 exercices réussis, dont 1 sans consulter la solution et 0 sans aucune aide');
        $this->assertSelectorTextContains('.certificate-exercises', '1 indice sur 2');
        $this->assertSelectorTextContains('.certificate-exercises', 'solution consultée');
        $this->assertSelectorExists('a[href^="https://www.linkedin.com/profile/add?startTask=CERTIFICATION_NAME"]');
        $linkedin = (string) $this->client->getCrawler()->filter('.share a[data-share-network="linkedin"]')->attr('href');
        $this->assertStringContainsString(rawurlencode('/certificat/'.$certificate->getCode().'?utm_source=linkedin&utm_medium=partage&utm_campaign=parcours'), $linkedin, 'Le partage mène à la page du certificat.');
        $this->assertStringStartsWith('J\'ai terminé le parcours « Découverte »', json_decode((string) $this->client->getCrawler()->filter('.share')->attr('data-share'), true)['text'] ?? '');

        $crawler = $this->client->request('GET', '/parcours/decouverte');
        $this->assertSame('/certificat/'.$certificate->getCode(), $crawler->filter('a.track-certificate')->attr('href'), 'Le parcours mène ensuite au certificat.');
        $this->assertCount(1, $crawler->filter('.track-done .share'), 'Le parcours terminé propose de partager le certificat.');
        $this->client->request('GET', '/parcours/decouverte/certificat');
        $this->assertResponseRedirects('/certificat/'.$certificate->getCode(), message: 'Pas de second certificat.');
    }

    public function testLaPageDeVerificationEstPubliqueMaisPasIndexee(): void
    {
        $user = $this->createUser();
        $this->completeTrack($user);
        $certificate = $this->claim($user);
        $this->client->getCookieJar()->clear();
        $this->client->restart();

        $crawler = $this->client->request('GET', '/certificat/'.strtolower($certificate->getCode()));
        $this->assertResponseRedirects('/certificat/'.$certificate->getCode(), 301, 'Le code se saisit aussi en minuscules.');

        $crawler = $this->client->request('GET', '/certificat/'.$certificate->getCode());
        $this->assertResponseIsSuccessful();
        $this->assertSame('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));
        $this->assertSelectorTextContains('main', 'Ada Lovelace');
        $this->assertSelectorNotExists('a[href*="linkedin.com"]', 'Seul le titulaire ajoute le certificat à son profil ou le partage.');
        $image = (string) $crawler->filter('meta[property="og:image"]')->attr('content');
        $this->assertMatchesRegularExpression('#^http://localhost/certificat/'.$certificate->getCode().'/image-de-partage\?v=[0-9a-f]{16}$#', $image);
        $this->assertSame('Certificat de réussite · Ada Lovelace : Découverte', $crawler->filter('meta[property="og:image:alt"]')->attr('content'));
        $this->client->request('GET', $image);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/png');

        $this->client->request('GET', '/certificat/'.$certificate->getCode().'/certificat.pdf');
        $this->assertResponseIsSuccessful();
        $this->assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/certificat/2222-2222');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testLaVerificationRevoqueEtLApprenantConteste(): void
    {
        $user = $this->createUser();
        $this->completeTrack($user);
        $certificate = $this->claim($user);
        $revocation = static::getContainer()->get(CertificateRevocation::class);

        $this->assertNull($revocation->revokeForExercise($certificate->getUser(), 'decouverte', 'inconnu', 'Rien à voir.'));
        $revoked = $revocation->revokeForExercise($certificate->getUser(), 'decouverte', '02-bonjour-prenom', 'Exercice « Bonjour prénom » non confirmé par la vérification.');
        $this->assertSame($certificate->getId(), $revoked?->getId());
        $this->assertEmailCount(1);
        $this->assertEmailTextBodyContains($this->getMailerMessage(), 'non confirmé par la vérification');

        $this->client->request('GET', '/certificat/'.$certificate->getCode());
        $this->assertSelectorTextContains('.certificate-status', 'Certificat révoqué');
        $this->assertSelectorTextContains('.certificate-status', 'non confirmé');
        $this->client->request('GET', '/certificat/'.$certificate->getCode().'/certificat.pdf');
        $this->assertResponseRedirects('/certificat/'.$certificate->getCode(), message: 'Plus de PDF pour un certificat révoqué.');

        $crawler = $this->client->request('GET', '/certificat/'.$certificate->getCode());
        $this->client->click($crawler->filter('a.certificate-contest')->link());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select#contact_form_subject option[value="certificat"][selected]');
        $this->assertSelectorTextContains('#contact_form_message', 'Je conteste la révocation de mon certificat '.$certificate->getCode());
        $this->client->submitForm('Envoyer le message', ['contact_form[message]' => 'Je conteste : j\'ai bien écrit ce code moi-même.'], serverParameters: self::ORIGIN);
        $this->assertResponseRedirects();

        $this->entityManager()->clear();
        $message = $this->entityManager()->getRepository(ContactMessage::class)->findOneBy([]);
        $this->assertSame(ContactSubject::Certificate, $message?->getSubject());
        $this->assertSame($certificate->getCode(), $message->getCertificate()?->getCode());

        $this->client->request('GET', '/certificat/'.$certificate->getCode());
        $this->assertSelectorTextContains('.certificate-status', 'contestation en cours');

        $this->client->request('GET', '/certificat/'.$certificate->getCode().'/image-de-partage');
        $this->assertResponseStatusCodeSame(404, 'Plus d\'image de partage non plus.');
    }

    public function testUnAutreCompteNeRattachePasLeCertificatDAutrui(): void
    {
        $user = $this->createUser();
        $this->completeTrack($user);
        $certificate = $this->claim($user);

        $this->client->loginUser($this->createUser('mallory@example.test', 'Mallory'));
        $this->client->request('GET', '/contact?objet=certificat&certificat='.$certificate->getCode());
        $this->assertSelectorTextNotContains('#contact_form_message', $certificate->getCode());
    }

    public function testLAdminRevoqueEtRetablit(): void
    {
        $user = $this->createUser();
        $this->completeTrack($user);
        $certificate = $this->claim($user);
        $admin = $this->createUser('admin@example.test', 'Admin');
        $admin->setRoles([User::ROLE_ADMIN]);
        $this->entityManager()->flush();
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/certificats');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString($certificate->getCode(), (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', sprintf('/admin/certificats/%d', $certificate->getId()));
        $this->assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Révoquer')->form(['reason' => 'Code copié d\'un autre apprenant.']), serverParameters: self::ORIGIN);
        $this->assertResponseRedirects();
        $this->assertEmailCount(1);

        $crawler = $this->client->request('GET', sprintf('/admin/certificats/%d', $certificate->getId()));
        $this->assertSelectorTextContains('.certificate-history', 'révoqué : Code copié');
        $this->client->submit($crawler->selectButton('Rétablir')->form(['note' => 'Contestation fondée.']), serverParameters: self::ORIGIN);
        $this->assertResponseRedirects();

        $this->entityManager()->clear();
        $certificate = static::getContainer()->get(CertificateRepository::class)->find($certificate->getId());
        $this->assertFalse($certificate?->isRevoked());
        $this->assertSame(['delivre', 'revoque', 'retabli'], array_column($certificate->getHistory(), 'action'));
    }

    public function testLeCertificatAttendLaConfirmationDesReussites(): void
    {
        $user = $this->createUser();
        $this->completeTrack($user);
        $this->verify($user, '01-bonjour', VerificationStatus::Pending);
        $this->verify($user, '02-bonjour-prenom', VerificationStatus::Unverifiable);
        $this->client->loginUser($user);

        $this->client->request('GET', '/parcours/decouverte/certificat');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.certificate-unconfirmed', 'Vérification en cours');
        $this->assertSelectorTextContains('.certificate-unconfirmed', '1 exercice en attente');
        $this->assertSelectorNotExists('#certificate_claim_holderName');

        $this->verify($user, '01-bonjour', VerificationStatus::Passed);
        $this->client->request('GET', '/parcours/decouverte/certificat');
        $this->assertSelectorNotExists('.certificate-unconfirmed', 'Une réussite impossible à rejouer est acceptée.');
        $this->assertSelectorExists('#certificate_claim_holderName');
    }

    public function testUneReussiteInfirmeeBloqueLeCertificat(): void
    {
        $user = $this->createUser();
        $this->completeTrack($user);
        $this->verify($user, '02-bonjour-prenom', VerificationStatus::Failed);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/parcours/decouverte/certificat');
        $this->assertResponseIsSuccessful();
        $title = static::getContainer()->get(ContentRepository::class)->findExercise('decouverte', '02-bonjour-prenom')?->title;
        $this->assertSelectorTextContains('.certificate-unconfirmed', (string) $title);
        $this->assertSelectorExists('.certificate-unconfirmed a[href*="/contact"]');
        $this->assertSelectorNotExists('#certificate_claim_holderName');
    }
}
