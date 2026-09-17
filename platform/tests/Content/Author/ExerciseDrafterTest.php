<?php

namespace App\Tests\Content\Author;

use App\Content\Author\ExerciseDrafter;
use App\Content\Author\ExerciseFiles;
use App\Ai\ModelClient;
use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class ExerciseDrafterTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../../..';

    private function drafter(MockHttpClient $client, string $cle = 'cle-de-test'): ExerciseDrafter
    {
        $content = new ContentRepository([self::ROOT.'/examples/packs'], new EnvironmentRegistry(self::ROOT.'/environments'), new Version(self::ROOT.'/VERSION'));

        return new ExerciseDrafter(new ModelClient($client, $cle, 'claude-sonnet-5'), $content, new EnvironmentRegistry(self::ROOT.'/environments'), new ExerciseFiles());
    }

    private function track(): \App\Content\Track
    {
        $content = new ContentRepository([self::ROOT.'/examples/packs'], new EnvironmentRegistry(self::ROOT.'/environments'), new Version(self::ROOT.'/VERSION'));

        return $content->findTrack('decouverte');
    }

    /** @param array<string, string> $fichiers */
    private static function reponse(array $fichiers): JsonMockResponse
    {
        return new JsonMockResponse([
            'content' => [
                ['type' => 'text', 'text' => 'Voici l\'exercice.'],
                ['type' => 'tool_use', 'name' => 'ecrire_exercice', 'input' => ['fichiers' => $fichiers]],
            ],
        ]);
    }

    public function testSansCleLaGenerationEstDesactivee(): void
    {
        $drafter = $this->drafter(new MockHttpClient(), cle: '');

        $this->assertFalse($drafter->disponible());
        $this->expectExceptionMessageMatches('/clé d\'API/');
        $drafter->brouillon($this->track(), '03-essai', 'Essai', 'un sujet', null);
    }

    public function testLeBrouillonReprendLesFichiersDuModele(): void
    {
        $requetes = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requetes) {
            $requetes[] = ['method' => $method, 'url' => $url, 'corps' => json_decode($options['body'], true), 'entetes' => $options['headers']];

            return self::reponse(['exercise.yaml' => "id: 03-essai\n", 'instructions.md' => '# Essai', 'starter/src/A.php' => '<?php']);
        });

        $fichiers = $this->drafter($client)->brouillon($this->track(), '03-essai', 'Essai', 'tester un voter', null);

        $this->assertSame(['exercise.yaml', 'instructions.md', 'starter/src/A.php'], array_keys($fichiers));
        $this->assertSame('https://api.anthropic.com/v1/messages', $requetes[0]['url']);
        $this->assertContains('x-api-key: cle-de-test', $requetes[0]['entetes']);
        $this->assertSame('ecrire_exercice', $requetes[0]['corps']['tool_choice']['name'], 'La réponse est imposée par l\'outil.');
        $this->assertStringContainsString('tester un voter', $requetes[0]['corps']['messages'][0]['content']);
        $this->assertStringContainsString('Bonjour Symfony', $requetes[0]['corps']['messages'][0]['content'], 'Le parcours existant sert de contexte.');
    }

    public function testUnCheminHorsDeLExerciceEstRefuse(): void
    {
        $client = new MockHttpClient(fn () => self::reponse(['exercise.yaml' => 'id: x', 'instructions.md' => '#', '../../evasion.txt' => 'oups']));

        $this->expectException(ContentException::class);
        $this->expectExceptionMessageMatches('/Chemin de fichier refusé/');

        $this->drafter($client)->brouillon($this->track(), '03-essai', 'Essai', 'sujet', null);
    }

    public function testUnExerciceIncompletEstRefuse(): void
    {
        $client = new MockHttpClient(fn () => self::reponse(['starter/src/A.php' => '<?php']));

        $this->expectExceptionMessageMatches('/a oublié exercise.yaml/');

        $this->drafter($client)->brouillon($this->track(), '03-essai', 'Essai', 'sujet', null);
    }

    public function testUneErreurDeLApiEstRapportee(): void
    {
        $client = new MockHttpClient(new JsonMockResponse(['error' => ['message' => 'crédit épuisé']], ['http_code' => 400]));

        $this->expectExceptionMessageMatches('/crédit épuisé/');

        $this->drafter($client)->brouillon($this->track(), '03-essai', 'Essai', 'sujet', null);
    }
}
