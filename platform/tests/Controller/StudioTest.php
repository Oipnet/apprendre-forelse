<?php

namespace App\Tests\Controller;

use App\Ai\ModelClient;
use App\Entity\User;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/** L'atelier écrit dans un pack : les tests travaillent sur une copie jetable du pack de démo. */
final class StudioTest extends WebTestCase
{
    use DatabaseTrait;

    private const string ROOT = __DIR__.'/../../..';
    private string $packs;
    private string $exercice;
    private ?string $cheminsInitiaux = null;

    protected function setUp(): void
    {
        $this->packs = sys_get_temp_dir().'/atelier-packs-'.bin2hex(random_bytes(4));
        (new Filesystem())->mirror(self::ROOT.'/examples/packs/demo', $this->packs.'/demo');
        $this->exercice = $this->packs.'/demo/tracks/decouverte/exercises/01-bonjour';
        $this->cheminsInitiaux = $_SERVER['CONTENT_PACKS_PATHS'] ?? null;
        $_SERVER['CONTENT_PACKS_PATHS'] = $_ENV['CONTENT_PACKS_PATHS'] = $this->packs;
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->packs);
        if (null === $this->cheminsInitiaux) {
            unset($_SERVER['CONTENT_PACKS_PATHS'], $_ENV['CONTENT_PACKS_PATHS']);
        } else {
            $_SERVER['CONTENT_PACKS_PATHS'] = $_ENV['CONTENT_PACKS_PATHS'] = $this->cheminsInitiaux;
        }
        parent::tearDown();
    }

    private function auteur(KernelBrowser $client): void
    {
        $this->resetDatabase();
        $utilisateur = $this->createUser('auteur@example.test', 'Auteur');
        $utilisateur->setRoles([User::ROLE_AUTEUR]);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($utilisateur);
    }

    /**
     * Remplace le modèle par un faux qui rend toujours `$posts` à l'outil des posts.
     *
     * @param list<array<string, mixed>> $posts
     */
    private function faireEcrireLesPosts(array $posts): void
    {
        $http = new MockHttpClient(fn () => new JsonMockResponse(['content' => [['type' => 'tool_use', 'name' => 'ecrire_posts', 'input' => ['posts' => $posts]]]]));
        static::getContainer()->set(ModelClient::class, new ModelClient($http, 'cle-de-test', 'claude-sonnet-5'));
    }

    public function testLAtelierEstReserveAuxAuteurs(): void
    {
        $client = static::createClient();
        $client->request('GET', '/atelier');
        $this->assertResponseRedirects('/connexion', null, 'Un visiteur est envoyé vers la connexion.');

        $this->resetDatabase();
        $client->loginUser($this->createUser());
        $client->request('GET', '/atelier');
        $this->assertResponseStatusCodeSame(403, 'Un apprenant ordinaire n\'entre pas dans l\'atelier.');
    }

    public function testLAtelierListeLesParcoursPuisLeursExercices(): void
    {
        $client = static::createClient();
        $this->auteur($client);
        $crawler = $client->request('GET', '/atelier');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['Découverte'], $crawler->filter('.st-card-title')->extract(['_text']));
        $this->assertSame('/atelier/decouverte', $crawler->filter('.st-card-title')->attr('href'));
        $this->assertSelectorTextContains('.st-aside-block', 'Gérer les');

        $crawler = $client->request('GET', '/atelier/decouverte');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.st-exercises', 'Bonjour Symfony');
        $this->assertSelectorTextContains('.st-figures', '2 exercices');

        $client->request('GET', '/atelier/inconnu');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testLesParcoursSeFiltrentEtSeCherchent(): void
    {
        $client = static::createClient();
        $this->auteur($client);
        $titres = fn (string $query) => $client->request('GET', '/atelier?'.$query)->filter('.st-card-title')->extract(['_text']);

        $this->assertSame(['Découverte'], $titres('etat=publies'));
        $this->assertSame([], $titres('etat=preparation'), 'Le pack de démo n\'a aucun parcours en préparation.');
        $this->assertSame(['Découverte'], $titres('recherche='.rawurlencode('avant-goût')), 'La recherche lit aussi la description.');
        $this->assertSame([], $titres('recherche=cobol'));
        $this->assertSelectorTextContains('.st-empty', 'Aucun parcours ne correspond');
        $this->assertSame(['Découverte'], $titres('etat=nawak'), 'Un état inconnu ne filtre rien.');
    }

    public function testEnregistrerReecritLesFichiersDeLExercice(): void
    {
        $client = static::createClient();
        $this->auteur($client);
        $client->jsonRequest('PUT', '/atelier/decouverte/01-bonjour', ['fichiers' => [
            'exercise.yaml' => file_get_contents($this->exercice.'/exercise.yaml'),
            'instructions.md' => "# Bonjour\n\nRéécrit par l'atelier.\n",
            'starter/src/Controller/BonjourController.php' => "<?php\n// départ\n",
            'solution/src/Controller/BonjourController.php' => "<?php\n// solution\n",
            'tests/BonjourTest.php' => "<?php\n// tests\n",
        ]]);

        $this->assertResponseIsSuccessful();
        $this->assertNull(json_decode((string) $client->getResponse()->getContent(), true)['format'], 'Le format reste valide.');
        $this->assertStringContainsString('Réécrit par l\'atelier', (string) file_get_contents($this->exercice.'/instructions.md'));
        $this->assertFileDoesNotExist($this->exercice.'/starter/templates', 'Les fichiers absents de l\'envoi sont retirés.');
    }

    public function testUnFormatInvalideEstSignaleSansPerdreLeTravail(): void
    {
        $client = static::createClient();
        $this->auteur($client);

        $client->jsonRequest('PUT', '/atelier/decouverte/01-bonjour', ['fichiers' => [
            'exercise.yaml' => "id: 01-bonjour\ntitle: Bonjour\n", // ni objectifs ni fichier éditable
            'instructions.md' => "# Bonjour\n",
        ]]);

        $this->assertResponseIsSuccessful();
        $corps = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($corps['enregistre'], 'Le travail est enregistré même s\'il ne compile pas encore.');
        $this->assertStringContainsString('objectif', (string) $corps['format']);
    }

    public function testUnCheminSuspectEstRefuse(): void
    {
        $client = static::createClient();
        $this->auteur($client);

        $client->jsonRequest('PUT', '/atelier/decouverte/01-bonjour', ['fichiers' => ['../../../evasion.txt' => 'oups']]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertFileDoesNotExist($this->packs.'/evasion.txt');
    }

    public function testCreerUnExerciceLInscritDansLeParcours(): void
    {
        $client = static::createClient();
        $this->auteur($client);

        $client->jsonRequest('POST', '/atelier/decouverte/nouveau', [
            'id' => '03-mon-exercice', 'titre' => 'Mon exercice', 'chapitre' => 'bonjour', 'base' => '02-bonjour-prenom',
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('/atelier/decouverte/03-mon-exercice', json_decode((string) $client->getResponse()->getContent(), true)['url']);
        $this->assertFileExists($this->packs.'/demo/tracks/decouverte/exercises/03-mon-exercice/exercise.yaml');
        $this->assertStringContainsString('- 03-mon-exercice', (string) file_get_contents($this->packs.'/demo/tracks/decouverte/track.yaml'));
    }

    public function testSupprimerUnExerciceLeRetireDuParcours(): void
    {
        $client = static::createClient();
        $this->auteur($client);
        $client->jsonRequest('POST', '/atelier/decouverte/nouveau', ['id' => '03-a-jeter', 'titre' => 'À jeter', 'chapitre' => 'bonjour']);
        $this->assertResponseStatusCodeSame(201);

        $client->request('DELETE', '/atelier/decouverte/03-a-jeter');

        $this->assertResponseIsSuccessful();
        $this->assertDirectoryDoesNotExist($this->packs.'/demo/tracks/decouverte/exercises/03-a-jeter');
        $this->assertStringNotContainsString('03-a-jeter', (string) file_get_contents($this->packs.'/demo/tracks/decouverte/track.yaml'));
    }

    public function testUnExerciceQuiSertDeBaseNePeutPasEtreSupprime(): void
    {
        $client = static::createClient();
        $this->auteur($client);

        $client->request('DELETE', '/atelier/decouverte/01-bonjour');

        $this->assertResponseStatusCodeSame(422, '02-bonjour-prenom part de 01-bonjour.');
        $this->assertStringContainsString('02-bonjour-prenom', json_decode((string) $client->getResponse()->getContent(), true)['erreur']);
        $this->assertFileExists($this->exercice.'/exercise.yaml');
    }

    public function testLaFicheDeCoursSEditeDepuisLAtelier(): void
    {
        $client = static::createClient();
        $this->auteur($client);
        $fiche = $this->packs.'/demo/tracks/decouverte/chapters/bonjour/lesson.md';

        $crawler = $client->request('GET', '/atelier/decouverte');
        $this->assertResponseIsSuccessful();
        $this->assertSame('/atelier/decouverte/chapitre/bonjour', $crawler->filter('.st-chapter-foot a')->attr('href'));

        $crawler = $client->request('GET', '/atelier/decouverte/chapitre/bonjour');
        $this->assertResponseIsSuccessful();
        $config = json_decode($crawler->filter('[data-studio-lesson]')->attr('data-config'), true);
        $this->assertStringStartsWith('## Ce que vous avez appris', $config['markdown']);
        $this->assertSame('/parcours/decouverte/chapitre/bonjour', $config['urls']['lire']);

        $client->jsonRequest('PUT', '/atelier/decouverte/chapitre/bonjour', ['markdown' => "## Réécrite\n\nDepuis l'atelier."]);
        $this->assertResponseIsSuccessful();
        $this->assertSame("## Réécrite\n\nDepuis l'atelier.\n", file_get_contents($fiche));

        $client->jsonRequest('POST', '/atelier/decouverte/chapitre/bonjour/apercu', ['markdown' => "## Titre\n\n<script>x</script> et `{prenom}`"]);
        $this->assertResponseIsSuccessful();
        $html = json_decode((string) $client->getResponse()->getContent(), true)['html'];
        $this->assertStringContainsString('<h2>Titre</h2>', $html);
        $this->assertStringNotContainsString('<script', $html, 'L\'aperçu passe par le même rendu que la plateforme.');

        $client->jsonRequest('POST', '/atelier/decouverte/chapitre/bonjour/squelette', []);
        $this->assertResponseIsSuccessful();
        $squelette = json_decode((string) $client->getResponse()->getContent(), true)['markdown'];
        $this->assertStringContainsString('- **Route** <!-- 01-bonjour -->', $squelette);
        $this->assertStringContainsString('## Réécrite', (string) file_get_contents($fiche), 'Proposé, pas enregistré.');

        $client->jsonRequest('PUT', '/atelier/decouverte/chapitre/bonjour', ['markdown' => "  \n"]);
        $this->assertResponseIsSuccessful();
        $this->assertFalse(json_decode((string) $client->getResponse()->getContent(), true)['fiche']);
        $this->assertFileDoesNotExist($fiche, 'Une fiche vide est retirée du chapitre…');
        $this->assertDirectoryDoesNotExist(\dirname($fiche), '… avec son dossier, devenu inutile.');
        $this->assertSame('Écrire la fiche de cours', trim($client->request('GET', '/atelier/decouverte')->filter('.st-chapter-foot a')->text()));

        $client->request('GET', '/atelier/decouverte/chapitre/inconnu');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testSansCleDApiLeBrouillonDeFicheEstRefuseClairement(): void
    {
        $client = static::createClient();
        $this->auteur($client);

        $client->jsonRequest('POST', '/atelier/decouverte/chapitre/bonjour/rediger', []);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', json_decode((string) $client->getResponse()->getContent(), true)['erreur']);
    }

    public function testSansCleDApiLaGenerationEstRefuseeClairement(): void
    {
        $client = static::createClient();
        $this->auteur($client);

        $client->jsonRequest('POST', '/atelier/decouverte/nouveau', [
            'id' => '03-avec-ia', 'titre' => 'Avec IA', 'chapitre' => 'bonjour', 'sujet' => 'tester un voter',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('clé d\'API', json_decode((string) $client->getResponse()->getContent(), true)['erreur']);
        $this->assertFileDoesNotExist($this->packs.'/demo/tracks/decouverte/exercises/03-avec-ia', 'Rien n\'est créé si la génération échoue.');
    }

    public function testCreerEnregistrerEtSupprimerUnExerciceDePratique(): void
    {
        $client = static::createClient();
        $this->auteur($client);
        $crawler = $client->request('GET', '/atelier/pratique');
        $this->assertStringContainsString('Lire un en-tête avec #[MapRequestHeader]', $crawler->filter('main')->text());

        $client->jsonRequest('POST', '/atelier/pratique/nouveau', ['pack' => 'demo', 'id' => 'mon-essai', 'titre' => 'Mon essai', 'environnement' => 'symfony-8']);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('/atelier/pratique/mon-essai', json_decode((string) $client->getResponse()->getContent(), true)['url']);
        $dossier = $this->packs.'/demo/practice/mon-essai';
        $yaml = (string) file_get_contents($dossier.'/exercise.yaml');
        $this->assertStringContainsString("environment: symfony-8\n", $yaml);
        $this->assertStringContainsString("visibility: admin\n", $yaml, 'Un nouvel exercice reste en préparation.');
        $this->assertStringNotContainsString('xp:', $yaml);
        $this->assertFileExists($dossier.'/tests/Pratique/MonTest.php');

        $client->request('GET', '/atelier/pratique/mon-essai');
        $this->assertResponseIsSuccessful('L\'exercice créé est valide et s\'ouvre dans l\'atelier.');
        $config = json_decode($client->getCrawler()->filter('[data-studio]')->attr('data-config'), true);
        $this->assertTrue($config['pratique']);
        $this->assertSame('/pratique/mon-essai', $config['urls']['jouer']);

        $client->jsonRequest('POST', '/atelier/pratique/nouveau', ['pack' => 'demo', 'id' => 'exemple-map-request-header', 'titre' => 'Doublon', 'environnement' => 'symfony-8']);
        $this->assertResponseStatusCodeSame(422);

        $client->jsonRequest('DELETE', '/atelier/pratique/exemple-map-request-header');
        $this->assertResponseIsSuccessful();
        $this->assertDirectoryDoesNotExist($this->packs.'/demo/practice/exemple-map-request-header');

        // Comme pour un exercice de parcours, le travail est enregistré et le format signalé.
        $client->jsonRequest('PUT', '/atelier/pratique/mon-essai', ['fichiers' => [...$config['fichiers'], 'exercise.yaml' => $yaml."xp: 10\n"]]);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('« xp » n\'a pas cours', (string) json_decode((string) $client->getResponse()->getContent(), true)['format']);
    }

    public function testVerifierRendLeVerdictDeContentCheck(): void
    {
        $client = static::createClient();
        $this->auteur($client);

        $client->request('POST', '/atelier/decouverte/01-bonjour/verifier');

        $this->assertResponseIsSuccessful();
        $verdict = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($verdict['ok'], implode("\n", $verdict['erreurs']));
        $this->assertSame([false, false], array_column($verdict['objectifs'], 'dejaValide'), 'Aucun objectif ne doit être validé au départ.');
    }

    public function testLesBrouillonsDePostSOuvrentDepuisLeParcoursEtDepuisLaPratique(): void
    {
        $client = static::createClient();
        $this->auteur($client);
        // Plusieurs requêtes avec le même faux modèle : sans cela, le noyau redémarre et le rétablit.
        $client->disableReboot();
        $this->faireEcrireLesPosts([['angle' => 'annonce', 'texte' => 'Un post.']]);

        $crawler = $client->request('GET', '/atelier/decouverte');
        $this->assertSame('/atelier/decouverte/post', $crawler->filter('.st-track-actions a')->eq(1)->attr('href'), 'Le parcours mène à ses brouillons de post.');

        $crawler = $client->request('GET', '/atelier/decouverte/post');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.lead', 'Découverte');
        $config = json_decode($crawler->filter('[data-post]')->attr('data-post'), true);
        $this->assertTrue($config['ia']);
        $this->assertSame('/atelier/decouverte/post', $config['urls']['generer']);
        $this->assertStringEndsWith('/parcours/decouverte', $config['urls']['publique'], 'Le post renvoie vers la page publique du parcours.');
        $this->assertSelectorExists('.st-post-form');

        $crawler = $client->request('GET', '/atelier/pratique/exemple-map-request-header/post');
        $this->assertResponseIsSuccessful();
        $config = json_decode($crawler->filter('[data-post]')->attr('data-post'), true);
        $this->assertSame('/atelier/pratique/exemple-map-request-header/post', $config['urls']['generer']);
        $this->assertStringEndsWith('/pratique/exemple-map-request-header', $config['urls']['publique']);

        $client->request('GET', '/atelier/inconnu/post');
        $this->assertResponseStatusCodeSame(404);
        $client->request('GET', '/atelier/pratique/inconnu/post');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testLesTroisBrouillonsSontProposesSansRienEcrireDansLePack(): void
    {
        $client = static::createClient();
        $this->auteur($client);
        $avant = file_get_contents($this->packs.'/demo/tracks/decouverte/track.yaml');
        $this->faireEcrireLesPosts([
            ['angle' => 'annonce', 'texte' => 'Deux exercices pour voir Symfony tourner.'],
            ['angle' => 'coulisses', 'texte' => 'Pourquoi deux exercices.'],
            ['angle' => 'pedagogique', 'texte' => "Une route, c'est une URL et un contrôleur."],
        ]);

        $client->jsonRequest('POST', '/atelier/decouverte/post', ['precision' => 'Insister sur les tests']);

        $this->assertResponseIsSuccessful();
        $posts = json_decode((string) $client->getResponse()->getContent(), true)['posts'];
        $this->assertSame(['Annonce', 'Coulisses', 'Pédagogique'], array_column($posts, 'label'));
        $this->assertStringContainsString('/parcours/decouverte', $posts[0]['texte'], 'Le lien manquant est ajouté au post.');
        $this->assertSame($avant, file_get_contents($this->packs.'/demo/tracks/decouverte/track.yaml'), 'Un post ne touche pas au contenu.');
    }

    public function testSansCleDApiLesPostsSontRefusesEtLaPageLExplique(): void
    {
        $client = static::createClient();
        $this->auteur($client);

        $crawler = $client->request('GET', '/atelier/decouverte/post');
        $this->assertResponseIsSuccessful();
        $this->assertFalse(json_decode($crawler->filter('[data-post]')->attr('data-post'), true)['ia']);
        $this->assertSelectorNotExists('.st-post-form');
        $this->assertSelectorTextContains('.st-hint', 'ANTHROPIC_API_KEY');
        $this->assertCount(1, $client->request('GET', '/atelier/decouverte')->filter('.st-track-actions a'), 'Sans clé, le parcours ne propose pas de post.');

        $client->jsonRequest('POST', '/atelier/decouverte/post', []);
        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', json_decode((string) $client->getResponse()->getContent(), true)['erreur']);
    }

}
