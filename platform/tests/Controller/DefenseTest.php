<?php

namespace App\Tests\Controller;

use App\Content\ContentRepository;
use App\Defense\DefenseManagement;
use App\Entity\Cohort;
use App\Entity\Defense;
use App\Entity\DefenseMode;
use App\Entity\User;
use App\Service\ProgressService;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Soutenances de fin de parcours : le chef ouvre des créneaux, l'apprenant qui a fini le parcours en réserve un,
 * l'évaluateur remplit la grille. Pack de test : « Bases de Symfony », un chapitre, un exercice.
 */
final class DefenseTest extends WebTestCase
{
    use DatabaseTrait;

    private const array ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];
    /** Le jeton CSRF sans état (« submit ») : valable avec l'en-tête Origin du site. */
    private const string JETON = 'csrf-token';

    private KernelBrowser $client;
    private ?string $cheminsInitiaux = null;

    protected function setUp(): void
    {
        $this->cheminsInitiaux = $_SERVER['CONTENT_PACKS_PATHS'] ?? null;
        $_SERVER['CONTENT_PACKS_PATHS'] = $_ENV['CONTENT_PACKS_PATHS'] = __DIR__.'/../Fixtures/packs/cohortes';
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        if (null === $this->cheminsInitiaux) {
            unset($_SERVER['CONTENT_PACKS_PATHS'], $_ENV['CONTENT_PACKS_PATHS']);
        } else {
            $_SERVER['CONTENT_PACKS_PATHS'] = $_ENV['CONTENT_PACKS_PATHS'] = $this->cheminsInitiaux;
        }
        parent::tearDown();
    }

    public function testLeChefOuvreUnCreneauQueLApprenantReserve(): void
    {
        $chef = $this->chef();
        $cohorte = $this->cohorte('iut-annecy', [$chef]);
        $ada = $this->createUser('ada@example.test', 'Ada', 'iut-annecy');
        $bob = $this->createUser('bob@example.test', 'Bob', 'iut-annecy');
        $this->terminer($ada);

        $this->client->request('GET', '/parcours/symfony-bases');
        $this->client->loginUser($ada);
        $this->client->request('GET', '/parcours/symfony-bases');
        $this->assertSelectorNotExists('.track-defense', 'Sans créneau ouvert, la page du parcours ne parle pas de soutenance.');

        $this->client->loginUser($chef);
        $crawler = $this->client->request('GET', '/cohorte/'.$cohorte->getId());
        $debut = new \DateTimeImmutable('+7 days 14:00');
        $form = $crawler->selectButton('Ouvrir le créneau')->form([
            'defense_slot[trackId]' => 'symfony-bases',
            'defense_slot[startsAt]' => $debut->format('Y-m-d\TH:i'),
            'defense_slot[durationMinutes]' => '30',
            'defense_slot[mode]' => 'online',
            'defense_slot[place]' => 'https://visio.example.test/salle-42',
        ]);
        $this->client->submit($form, [], self::ORIGIN);
        $this->assertResponseRedirects('/cohorte/'.$cohorte->getId().'#soutenances');
        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'Créneau ouvert le '.$debut->format('d/m/Y à H:i'));
        $this->assertStringContainsString('libre', $crawler->filter('.defense-slots tbody tr')->text());

        $this->client->loginUser($bob);
        $crawler = $this->client->request('GET', '/parcours/symfony-bases');
        $this->assertSelectorTextContains('.track-defense', 'réussissez tous les exercices pour réserver votre créneau');
        $this->assertCount(0, $crawler->filter('.defense-slot'), 'Bob n\'a pas fini le parcours : pas de créneau à réserver.');
        $creneau = $this->creneaux()[0];
        $this->client->request('POST', '/parcours/symfony-bases/soutenance/'.$creneau->getId().'/reserver', ['_token' => 'forcé'], server: self::ORIGIN);
        $this->assertFalse($this->recharger($creneau)->isBooked(), 'Un jeton forcé ne réserve rien.');

        $this->client->loginUser($ada);
        $crawler = $this->client->request('GET', '/parcours/symfony-bases');
        $this->assertCount(1, $crawler->filter('.defense-slot'));
        $this->client->submit($crawler->filter('.defense-slot')->selectButton('Réserver')->form(), [], self::ORIGIN);
        $this->assertResponseRedirects('/parcours/symfony-bases#soutenance');

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        \assert($email instanceof Email);
        $this->assertSame('ada@example.test', $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('Votre soutenance « Bases de Symfony » le '.$debut->format('d/m/Y à H:i'), (string) $email->getSubject());
        $this->assertStringContainsString('https://visio.example.test/salle-42', (string) $email->getTextBody());
        $ics = $email->getAttachments()[0];
        $this->assertSame('soutenance-'.$debut->format('Y-m-d').'.ics', $ics->getFilename());
        $this->assertStringContainsString('DTSTART:'.$debut->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'), $ics->getBody());
        $this->assertStringContainsString('SUMMARY:Soutenance · Bases de Symfony', $ics->getBody());

        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-success', 'Soutenance réservée');
        $this->assertSelectorTextContains('.defense-upcoming', 'Le '.$debut->format('d/m/Y à H:i'));
        $this->assertCount(0, $crawler->filter('.defense-slot'), 'Un créneau réservé, pas un second.');

        $this->client->request('GET', '/parcours/symfony-bases/soutenance/'.$creneau->getId().'/agenda.ics');
        $this->assertResponseIsSuccessful();
        $this->assertStringStartsWith('text/calendar', (string) $this->client->getResponse()->headers->get('Content-Type'));

        // Un autre apprenant qui a fini le parcours arrive après : le créneau est pris.
        $cleo = $this->createUser('cleo@example.test', 'Cléo', 'iut-annecy');
        $this->terminer($cleo);
        $this->client->loginUser($cleo);
        $this->client->request('POST', '/parcours/symfony-bases/soutenance/'.$creneau->getId().'/reserver', ['_token' => self::JETON], server: self::ORIGIN);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-danger', 'Ce créneau vient d\'être pris');
        $this->client->request('GET', '/parcours/symfony-bases/soutenance/'.$creneau->getId().'/agenda.ics');
        $this->assertResponseStatusCodeSame(404, 'L\'invitation d\'un autre reste privée.');

        // Ada annule : le créneau redevient libre, sans convocation de plus.
        $this->client->loginUser($ada);
        $crawler = $this->client->request('GET', '/parcours/symfony-bases');
        $this->client->submit($crawler->selectButton('Annuler ce créneau')->form(), [], self::ORIGIN);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-success', 'Créneau annulé');
        $this->assertFalse($this->recharger($creneau)->isBooked());
        $this->assertCount(1, $this->client->getCrawler()->filter('.defense-slot'));
    }

    public function testLeChefLitLeDossierEtEvalue(): void
    {
        $chef = $this->chef();
        $cohorte = $this->cohorte('iut-annecy', [$chef]);
        $ada = $this->createUser('ada@example.test', 'Ada', 'iut-annecy');
        $this->terminer($ada, '<?php // le code d\'Ada');
        $premier = $this->ouvrir($cohorte, '+3 days 10:00', DefenseMode::OnSite, '12 rue de l\'École, salle B204');
        $second = $this->ouvrir($cohorte, '+10 days 10:00', DefenseMode::OnSite, '12 rue de l\'École, salle B204');
        static::getContainer()->get(DefenseManagement::class)->book($this->recharger($ada), $this->recharger($premier));

        $this->client->loginUser($chef);
        $crawler = $this->client->request('GET', '/cohorte/'.$cohorte->getId());
        $this->client->click($crawler->selectLink('Dossier et évaluation')->link());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#dossier', '1 / 1 exercices réussis');
        $this->assertSelectorTextContains('.defense-code', '<?php // le code d\'Ada');

        // Sans niveau pour chaque chapitre, rien n'est enregistré.
        $form = $this->client->getCrawler()->selectButton('Enregistrer l\'évaluation')->form();
        $this->client->request('POST', $form->getUri(), ['defense_evaluation' => ['outcome' => 'retake', '_token' => $form->get('defense_evaluation[_token]')->getValue()]], server: self::ORIGIN);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'remplissez chaque ligne de la grille');
        $this->assertFalse($this->recharger($premier)->isEvaluated());

        $this->client->submit($form, [
            'defense_evaluation[grades][c0]' => 'in_progress',
            'defense_evaluation[outcome]' => 'retake',
            'defense_evaluation[comment]' => 'Revoir l\'injection de dépendances.',
        ], self::ORIGIN);
        $this->assertResponseRedirects();
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        \assert($email instanceof Email);
        $this->assertStringContainsString('Résultat de votre soutenance « Bases de Symfony »', (string) $email->getSubject());
        $this->assertStringContainsString('Premier chapitre Symfony : En cours d\'acquisition', (string) $email->getTextBody());
        $this->assertStringContainsString('Revoir l\'injection de dépendances.', (string) $email->getTextBody());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'Évaluation enregistrée : à repasser.');
        $this->assertSame('Prof', $this->recharger($premier)->getEvaluatorName());

        // À repasser : Ada voit sa grille et peut réserver le second créneau.
        $this->client->loginUser($ada);
        $crawler = $this->client->request('GET', '/parcours/symfony-bases');
        $this->assertSelectorTextContains('.defense-result', 'À repasser');
        $this->assertSelectorTextContains('.defense-result', 'Revoir l\'injection de dépendances.');
        $this->assertCount(1, $crawler->filter('.defense-slot'));
        $this->client->submit($crawler->filter('.defense-slot')->selectButton('Réserver')->form(), [], self::ORIGIN);
        $this->assertTrue($this->recharger($second)->isBooked());

        // Validée : plus de créneau à réserver.
        $this->client->loginUser($chef);
        $crawler = $this->client->request('GET', '/cohorte/'.$cohorte->getId().'/soutenances/'.$second->getId());
        $this->client->submit($crawler->selectButton('Enregistrer l\'évaluation')->form([
            'defense_evaluation[grades][c0]' => 'acquired',
            'defense_evaluation[outcome]' => 'passed',
        ]), [], self::ORIGIN);
        $this->ouvrir($cohorte, '+20 days 10:00', DefenseMode::Online, 'https://visio.example.test/b');
        $this->client->loginUser($ada);
        $crawler = $this->client->request('GET', '/parcours/symfony-bases');
        $this->assertSelectorTextContains('.defense-result', 'Soutenance validée');
        $this->assertCount(0, $crawler->filter('.defense-slot'), 'Une soutenance validée ne se repasse pas.');

        // L'export reprend chaque créneau, du plus ancien au plus récent.
        $this->client->loginUser($chef);
        $this->client->request('GET', '/cohorte/'.$cohorte->getId().'/soutenances.csv');
        $this->assertResponseIsSuccessful();
        $this->assertSame('attachment; filename=soutenances-iut-annecy.csv', $this->client->getResponse()->headers->get('Content-Disposition'));
        $lignes = array_map(static fn (string $l) => str_getcsv($l, ';', escape: ''), explode("\n", trim((string) $this->client->getResponse()->getContent())));
        $this->assertCount(4, $lignes, 'Une ligne d\'en-tête, trois créneaux.');
        $this->assertSame(['Ada', 'À repasser', 'Prof', 'Premier chapitre Symfony : En cours d\'acquisition'], [$lignes[1][6], $lignes[1][8], $lignes[1][9], $lignes[1][11]]);
        $this->assertSame('Soutenance validée', $lignes[2][8]);
        $this->assertSame('Libre', $lignes[3][8]);
    }

    public function testLesSoutenancesSontCloisonnees(): void
    {
        $chef = $this->chef();
        $autreChef = $this->chef('collegue@example.test', 'Collègue');
        $mienne = $this->cohorte('iut-annecy', [$chef]);
        $autre = $this->cohorte('iut-lyon', [$autreChef]);
        $ada = $this->createUser('ada@example.test', 'Ada', 'iut-annecy');
        $leo = $this->createUser('leo@example.test', 'Léo', 'iut-lyon');
        $this->terminer($ada);
        $this->terminer($leo);
        $creneau = $this->ouvrir($mienne, '+3 days 10:00', DefenseMode::Online, 'https://visio.example.test/a');
        static::getContainer()->get(DefenseManagement::class)->book($this->recharger($ada), $this->recharger($creneau));
        $libre = $this->ouvrir($mienne, '+4 days 10:00', DefenseMode::Online, 'https://visio.example.test/a');

        $this->client->loginUser($autreChef);
        foreach (['/cohorte/'.$mienne->getId().'/soutenances/'.$creneau->getId(), '/cohorte/'.$mienne->getId().'/soutenances.csv'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseStatusCodeSame(403, sprintf('%s reste aux chefs de la cohorte.', $url));
        }
        $this->client->request('GET', '/cohorte/'.$autre->getId().'/soutenances/'.$creneau->getId());
        $this->assertResponseStatusCodeSame(404, 'Un créneau d\'une autre cohorte ne se lit pas sous l\'adresse de la sienne.');

        $this->client->loginUser($chef);
        $this->client->request('POST', '/cohorte/'.$mienne->getId().'/soutenances/'.$creneau->getId().'/supprimer', ['_token' => self::JETON], server: self::ORIGIN);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'Ce créneau est réservé');
        $this->client->request('POST', '/cohorte/'.$mienne->getId().'/soutenances/'.$libre->getId().'/supprimer', ['_token' => self::JETON], server: self::ORIGIN);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'Créneau supprimé.');
        $this->assertCount(1, $this->creneaux());

        // Une visio sans lien https n'ouvre pas de créneau.
        $crawler = $this->client->request('GET', '/cohorte/'.$mienne->getId());
        $this->client->submit($crawler->selectButton('Ouvrir le créneau')->form([
            'defense_slot[trackId]' => 'symfony-bases',
            'defense_slot[startsAt]' => (new \DateTimeImmutable('+5 days 9:00'))->format('Y-m-d\TH:i'),
            'defense_slot[mode]' => 'online',
            'defense_slot[place]' => 'salle B204',
        ]), [], self::ORIGIN);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'Le lien de la visio doit commencer par https://.');
        $this->assertCount(1, $this->creneaux());

        // Léo, d'une autre cohorte, ne réserve pas un créneau d'Annecy.
        $libre = $this->ouvrir($mienne, '+6 days 10:00', DefenseMode::Online, 'https://visio.example.test/a');
        $this->client->loginUser($leo);
        $crawler = $this->client->request('GET', '/parcours/symfony-bases');
        $this->assertSelectorNotExists('.track-defense', 'Sa cohorte n\'organise pas de soutenance.');
        $this->client->request('POST', '/parcours/symfony-bases/soutenance/'.$libre->getId().'/reserver', ['_token' => self::JETON], server: self::ORIGIN);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-danger', 'Ce créneau est réservé à une autre cohorte.');
        $this->client->request('POST', '/parcours/laravel-bases/soutenance/'.$libre->getId().'/reserver', ['_token' => self::JETON], server: self::ORIGIN);
        $this->assertResponseStatusCodeSame(404, 'Le créneau d\'un autre parcours n\'existe pas à cette adresse.');
        $this->assertFalse($this->recharger($libre)->isBooked());

        $this->client->restart();
        $this->client->request('POST', '/parcours/symfony-bases/soutenance/'.$libre->getId().'/reserver');
        $this->assertResponseRedirects('/connexion', message: 'Un anonyme est envoyé à la connexion.');
    }

    private function terminer(User $user, string $code = '<?php // réussi'): void
    {
        $exercice = static::getContainer()->get(ContentRepository::class)->findExercise('symfony-bases', 'e1');
        $progress = static::getContainer()->get(ProgressService::class);
        $progress->saveDraft($user, $exercice, ['src/Controller/BonjourController.php' => $code], 0);
        $progress->complete($user, $exercice, 0);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function ouvrir(Cohort $cohort, string $quand, DefenseMode $mode, string $lieu): Defense
    {
        return static::getContainer()->get(DefenseManagement::class)->openSlot($this->recharger($cohort), 'symfony-bases', new \DateTimeImmutable($quand), 30, $mode, $lieu);
    }

    private function chef(string $email = 'prof@example.test', string $nom = 'Prof'): User
    {
        $chef = $this->createUser($email, $nom);
        $chef->setRoles([User::ROLE_CHEF_COHORTE]);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $chef;
    }

    /** @param list<User> $chefs */
    private function cohorte(string $code, array $chefs): Cohort
    {
        $cohort = $this->createCohort($code);
        foreach ($chefs as $chef) {
            $cohort->addChef($chef);
        }
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $cohort;
    }

    /** @return list<Defense> */
    private function creneaux(): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(Defense::class)->findBy([], ['startsAt' => 'ASC']);
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function recharger(object $entity): object
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        return $entityManager->find($entity::class, $entity->getId()) ?? throw new \LogicException('Disparu.');
    }
}
