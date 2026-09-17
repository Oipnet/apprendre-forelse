<?php

namespace App\Tests\Command;

use App\Api\FeedbackInput;
use App\Content\ContentRepository;
use App\Service\FeedbackService;
use App\Service\ProgressService;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BetaExportTest extends KernelTestCase
{
    use DatabaseTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
    }

    /** @return list<list<string>> lignes du CSV (BOM retiré), cellules séparées */
    private function export(string $command, array $options = []): array
    {
        $tester = new CommandTester((new Application(self::$kernel))->find($command));
        $tester->execute($options);
        $tester->assertCommandIsSuccessful();
        $csv = $tester->getDisplay(true);
        $this->assertStringStartsWith("\u{FEFF}", $csv, 'BOM UTF-8 pour Excel.');

        // fgetcsv (et non un découpage par ligne) : un message peut contenir des retours à la ligne.
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $rows = [];
        while (false !== ($row = fgetcsv($stream, null, ';', '"', ''))) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    public function testProgressionParApprenantEtParExerciceMemeNonCommence(): void
    {
        $container = static::getContainer();
        $ada = $this->createUser('ada@example.test', 'Ada', 'iut-2026');
        $this->createUser('bob@example.test', 'Bob', 'devs-2026');
        $content = $container->get(ContentRepository::class);
        $progress = $container->get(ProgressService::class);
        $first = $content->findExercise('decouverte', '01-bonjour');
        $progress->saveDraft($ada, $first, [], 1);
        $progress->complete($ada, $first, 1);

        $rows = $this->export('beta:progress', ['--cohort' => 'iut-2026']);

        $this->assertSame(['cohorte', 'pseudo', 'email', 'parcours', 'chapitre', 'position', 'exercice', 'titre', 'statut', 'indices', 'xp', 'commence_le', 'termine_le', 'duree_min'], $rows[0]);
        $byExercise = [];
        foreach (array_slice($rows, 1) as $row) {
            $this->assertSame(['iut-2026', 'Ada', 'ada@example.test'], array_slice($row, 0, 3), 'Bob (autre cohorte) est exclu.');
            $byExercise[$row[6]] = $row;
        }
        $this->assertArrayHasKey('01-bonjour', $byExercise);
        $this->assertArrayHasKey('02-bonjour-prenom', $byExercise);
        $this->assertSame(['decouverte', 'completed', '1', '38'], [$byExercise['01-bonjour'][3], $byExercise['01-bonjour'][8], $byExercise['01-bonjour'][9], $byExercise['01-bonjour'][10]]);
        $this->assertSame('0', $byExercise['01-bonjour'][13], 'Réussi dans la minute : durée 0.');
        $this->assertSame('todo', $byExercise['02-bonjour-prenom'][8], 'Un exercice jamais ouvert apparaît quand même.');
        $this->assertSame('', $byExercise['02-bonjour-prenom'][11]);
    }

    public function testRetoursAvecFiltreDeCohorteEtFichierDeSortie(): void
    {
        $container = static::getContainer();
        $ada = $this->createUser('ada@example.test', 'Ada', 'iut-2026');
        $bob = $this->createUser('bob@example.test', 'Bob', 'devs-2026');
        $exercise = $container->get(ContentRepository::class)->findExercise('decouverte', '01-bonjour');
        $feedback = $container->get(FeedbackService::class);
        $feedback->record($ada, $exercise, new FeedbackInput('too-hard', "Dur ; avec un « ; » dedans\net deux lignes", 2, false));
        $feedback->record($bob, $exercise, new FeedbackInput('bug', 'Cassé', 0, true));

        $rows = $this->export('beta:feedback', ['--cohort' => 'iut-2026']);
        $this->assertCount(2, $rows);
        $this->assertSame(['date', 'cohorte', 'pseudo', 'parcours', 'exercice', 'type', 'indices', 'reussi', 'message'], $rows[0]);
        $this->assertSame(['iut-2026', 'Ada', 'decouverte', '01-bonjour', 'Trop difficile', '2', 'non'], array_slice($rows[1], 1, 7));

        $path = sys_get_temp_dir().'/beta-feedback-'.bin2hex(random_bytes(4)).'.csv';
        try {
            $tester = new CommandTester((new Application(self::$kernel))->find('beta:feedback'));
            $tester->execute(['--output-file' => $path]);
            $tester->assertCommandIsSuccessful();
            $this->assertStringContainsString('2 retour(s)', $tester->getDisplay());
            $this->assertStringContainsString("\"Dur ; avec un « ; » dedans\net deux lignes\"", (string) file_get_contents($path), 'Le message est correctement échappé.');
        } finally {
            @unlink($path);
        }
    }
}
