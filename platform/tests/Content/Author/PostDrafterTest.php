<?php

namespace App\Tests\Content\Author;

use App\Ai\ModelClient;
use App\Content\Author\PostDrafter;
use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Tests\BrandingTrait;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/** Les brouillons de post travaillent sur le pack de démonstration, sans rien écrire : ils ne produisent que du texte. */
final class PostDrafterTest extends TestCase
{
    use BrandingTrait;

    private const string ROOT = __DIR__.'/../../../..';
    private const string URL = 'https://exemple.test/parcours/decouverte';

    /** @var list<array{corps: array<string, mixed>}> */
    private array $requetes = [];

    private function content(): ContentRepository
    {
        return new ContentRepository([self::ROOT.'/examples/packs'], new EnvironmentRegistry(self::ROOT.'/environments'), new Version(self::ROOT.'/VERSION'));
    }

    /** @param list<array<string, mixed>> $posts */
    private function drafter(array $posts, string $cle = 'cle-de-test', string $modelePost = ''): PostDrafter
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($posts) {
            $this->requetes[] = ['corps' => json_decode($options['body'], true)];

            return new JsonMockResponse(['content' => [['type' => 'tool_use', 'name' => 'ecrire_posts', 'input' => ['posts' => $posts]]]]);
        });

        return new PostDrafter(new ModelClient($client, $cle, 'claude-sonnet-5'), $this->content(), new EnvironmentRegistry(self::ROOT.'/environments'), self::branding(), $modelePost);
    }

    /**
     * @param list<array<string, mixed>> $posts
     *
     * @return list<array{angle: string, label: string, texte: string, caracteres: int}>
     */
    private function parcours(array $posts, string $precision = ''): array
    {
        return $this->drafter($posts)->pourParcours($this->content()->findTrack('decouverte'), self::URL, $precision);
    }

    public function testSansCleAucunPostNEstPropose(): void
    {
        $this->assertFalse($this->drafter([], cle: '')->disponible());
    }

    public function testLeParcoursEstDecritAuModeleAvecSonContenu(): void
    {
        $posts = $this->parcours([
            ['angle' => 'annonce', 'texte' => "Deux exercices pour voir Symfony tourner.\n\n".self::URL],
            ['angle' => 'coulisses', 'texte' => "Pourquoi deux exercices et pas dix.\n\n".self::URL],
            ['angle' => 'pedagogique', 'texte' => "Une route, c'est une URL et un contrôleur.\n\n".self::URL],
        ]);

        $this->assertSame(['Annonce', 'Coulisses', 'Pédagogique'], array_column($posts, 'label'));
        $this->assertSame(['annonce', 'coulisses', 'pedagogique'], array_column($posts, 'angle'));

        $corps = $this->requetes[0]['corps'];
        $this->assertSame('ecrire_posts', $corps['tool_choice']['name'], 'La forme de la réponse est imposée par l\'outil.');
        $message = $corps['messages'][0]['content'];
        $this->assertStringContainsString('« Découverte »', $message);
        $this->assertStringContainsString('Un avant-goût de Symfony en deux exercices.', $message);
        $this->assertStringContainsString('Bonjour Symfony (2 exercices)', $message, 'Les chapitres donnent le plan du parcours.');
        $this->assertStringContainsString('Route, Contrôleur, Paramètre de route', $message, 'Les notions viennent des exercices.');
        $this->assertStringContainsString(self::URL, $message);
        $this->assertStringContainsString(self::URL, $corps['system'], 'Le modèle sait quel lien mettre dans le post.');
    }

    public function testLeLienEstAjouteQuandLeModeleLOublie(): void
    {
        $posts = $this->parcours([
            ['angle' => 'annonce', 'texte' => 'Un post sans lien.'],
            ['angle' => 'coulisses', 'texte' => 'Un post avec son lien : '.self::URL],
        ]);

        $this->assertSame("Un post sans lien.\n\n".self::URL, $posts[0]['texte'], 'Un post sans lien ne sert à rien : on l\'ajoute.');
        $this->assertSame('Un post avec son lien : '.self::URL, $posts[1]['texte'], 'Le lien déjà présent n\'est pas doublé.');
        $this->assertSame(mb_strlen($posts[0]['texte']), $posts[0]['caracteres'], 'Le compte de caractères porte sur le post final.');
    }

    public function testUnPostSansAngleConnuOuSansTexteEstIgnore(): void
    {
        $posts = $this->parcours([
            ['angle' => 'annonce', 'texte' => '  Le seul bon.  '],
            ['angle' => 'promotionnel', 'texte' => 'Angle inventé.'],
            ['angle' => 'coulisses', 'texte' => "  \n "],
            ['angle' => 'pedagogique'],
            'pas un objet',
        ]);

        $this->assertCount(1, $posts);
        $this->assertStringStartsWith('Le seul bon.', $posts[0]['texte'], 'Le texte est débarrassé de ses blancs.');
    }

    public function testUneReponseSansAucunPostExploitableEstRefusee(): void
    {
        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('aucun post exploitable');

        $this->parcours([['angle' => 'annonce', 'texte' => '   ']]);
    }

    public function testLaDemandeDeLAuteurEstTransmiseAuModele(): void
    {
        $this->parcours([['angle' => 'annonce', 'texte' => 'Un post.']], 'Insister sur les tests automatiques');

        $this->assertStringContainsString('Insister sur les tests automatiques', $this->requetes[0]['corps']['messages'][0]['content']);
    }

    public function testLesPostsPeuventAvoirLeurPropreModele(): void
    {
        $track = $this->content()->findTrack('decouverte');
        $post = [['angle' => 'annonce', 'texte' => 'Un post.']];

        $this->drafter($post)->pourParcours($track, self::URL);
        $this->assertSame('claude-sonnet-5', $this->requetes[0]['corps']['model'], 'Sans AI_MODEL_POST, les posts suivent AI_MODEL.');

        $this->drafter($post, modelePost: 'claude-opus-5')->pourParcours($track, self::URL);
        $this->assertSame('claude-opus-5', $this->requetes[1]['corps']['model'], 'AI_MODEL_POST ne vaut que pour les posts.');
    }

    public function testUnExerciceDePratiqueEstDecritParSonResumeEtSesConsignes(): void
    {
        $practice = $this->content()->findPractice('exemple-map-request-header');
        $url = 'https://exemple.test/pratique/exemple-map-request-header';

        $posts = $this->drafter([['angle' => 'pedagogique', 'texte' => 'Un post.']])->pourPratique($practice, $url);

        $this->assertCount(1, $posts);
        $message = $this->requetes[0]['corps']['messages'][0]['content'];
        $this->assertStringContainsString('« Lire un en-tête avec #[MapRequestHeader] »', $message);
        $this->assertStringContainsString('Un en-tête HTTP arrive directement en argument du contrôleur', $message);
        $this->assertStringContainsString('nouveauté de la version 8.1', $message);
        $this->assertStringContainsString('Contrôleur, HttpKernel', $message);
        $this->assertStringContainsString('Consignes données à l\'apprenant', $message);
        $this->assertStringContainsString('https://github.com/symfony/symfony/pull/51379', $message);
        $this->assertStringContainsString($url, $message);
    }
}
