<?php

namespace App\Tests\Ai;

use App\Ai\Mentor;
use App\Ai\ModelClient;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\Exercise;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class MentorTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    /** @var list<array{corps: array<string, mixed>}> */
    private array $requetes = [];

    private function content(): ContentRepository
    {
        return new ContentRepository([self::ROOT.'/examples/packs'], new EnvironmentRegistry(self::ROOT.'/environments'), new Version(self::ROOT.'/VERSION'));
    }

    private function exercice(): Exercise
    {
        return $this->content()->findExercise('decouverte', '01-bonjour');
    }

    /** @param array<string, mixed> $entree */
    private function mentor(string $outil, array $entree, string $cle = 'cle-de-test'): Mentor
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($outil, $entree) {
            $this->requetes[] = ['corps' => json_decode($options['body'], true)];

            return new JsonMockResponse(['content' => [['type' => 'tool_use', 'name' => $outil, 'input' => $entree]]]);
        });

        return new Mentor(new ModelClient($client, $cle, 'claude-sonnet-5'), $this->content());
    }

    public function testSansCleLeMentorEstAbsent(): void
    {
        $this->assertFalse($this->mentor('revue_de_code', [], cle: '')->disponible());
    }

    public function testLaRevueTransmetLeCodeDeLApprenantEtLaSolutionEnReference(): void
    {
        $mentor = $this->mentor('revue_de_code', [
            'summary' => ' Propre et lisible. ',
            'points' => [
                ['file' => 'src/Controller/BonjourController.php', 'line' => 12, 'message' => 'Le nom de route pourrait être plus explicite.', 'snippet' => "#[Route('/bonjour', name: 'app_bonjour')]\n"],
                ['file' => 'src/Controller/BonjourController.php', 'line' => 'douze', 'message' => 'Sans ligne.', 'snippet' => ''],
                ['file' => 'x', 'line' => null, 'message' => '   ', 'snippet' => null],
            ],
        ]);

        $revue = $mentor->revue($this->exercice(), [
            'src/Controller/BonjourController.php' => '<?php // mon code',
            'config/services.yaml' => 'jamais transmis',
        ]);

        $this->assertSame('Propre et lisible.', $revue['summary']);
        $this->assertCount(2, $revue['points'], 'Un point sans message est ignoré.');
        $this->assertSame(['file' => 'src/Controller/BonjourController.php', 'line' => 12, 'message' => 'Le nom de route pourrait être plus explicite.', 'snippet' => "#[Route('/bonjour', name: 'app_bonjour')]"], $revue['points'][0]);
        $this->assertNull($revue['points'][1]['line'], 'Une ligne qui n\'est pas un entier devient null.');
        $this->assertNull($revue['points'][1]['snippet'], 'Un extrait vide devient null.');

        $corps = $this->requetes[0]['corps'];
        $this->assertSame('revue_de_code', $corps['tool_choice']['name']);
        $message = $corps['messages'][0]['content'];
        $this->assertStringContainsString('<?php // mon code', $message);
        $this->assertStringNotContainsString('jamais transmis', $message, 'Seuls les fichiers éditables sont transmis.');
        $this->assertStringContainsString('Solution de référence', $message);
        $this->assertStringContainsString('Bonjour Symfony', $message, 'Les consignes de l\'exercice donnent le contexte.');
    }

    public function testLExplicationNeTransmetPasLaSolution(): void
    {
        $mentor = $this->mentor('expliquer_erreur', [
            'cause' => 'Symfony ne trouve pas de route pour /bonjour.',
            'piste' => 'Regardez l\'attribut au-dessus de la méthode du contrôleur.',
            'file' => 'src/Controller/BonjourController.php',
        ]);

        $explication = $mentor->expliquer($this->exercice(), ['src/Controller/BonjourController.php' => '<?php'], "No route found for \"GET http://localhost/bonjour\"\n".str_repeat('trace ', 3000), 'preview');

        $this->assertSame('Symfony ne trouve pas de route pour /bonjour.', $explication['cause']);
        $this->assertSame('src/Controller/BonjourController.php', $explication['file']);
        $message = $this->requetes[0]['corps']['messages'][0]['content'];
        $this->assertStringContainsString('No route found', $message);
        $this->assertStringContainsString('(tronqué)', $message, 'Une erreur interminable est coupée.');
        $this->assertStringNotContainsString('Solution de référence', $message, 'Le mentor n\'a pas la solution sous les yeux quand il explique une erreur.');
        $this->assertStringContainsString('réponse HTTP en erreur', $message);
    }

    public function testUnFichierInconnuDansLExplicationEstIgnore(): void
    {
        $mentor = $this->mentor('expliquer_erreur', ['cause' => 'c', 'piste' => 'p', 'file' => 'vendor/autoload.php']);

        $this->assertNull($mentor->expliquer($this->exercice(), ['src/Controller/BonjourController.php' => '<?php'], 'Erreur', 'tests')['file']);
    }
}
