<?php

namespace App\Tests\Service;

use App\Content\Chapter;
use App\Content\Track;
use App\Entity\ExerciseProgress;
use App\Entity\User;
use App\Service\TrackProgress;
use PHPUnit\Framework\TestCase;

final class TrackProgressTest extends TestCase
{
    private Track $track;
    private User $user;

    protected function setUp(): void
    {
        $this->track = new Track('t', 'p', 'Parcours', '', 'symfony-8', [
            new Chapter('c1', 'Un', ['e1', 'e2']),
            new Chapter('c2', 'Deux', ['e3']),
        ], '/tmp');
        $this->user = new User();
    }

    private function done(string $id, int $xp): ExerciseProgress
    {
        $progress = new ExerciseProgress($this->user, 't', $id);
        $progress->complete(0, $xp, new \DateTimeImmutable());

        return $progress;
    }

    private function draft(string $id): ExerciseProgress
    {
        $progress = new ExerciseProgress($this->user, 't', $id);
        $progress->saveDraft([], 0, new \DateTimeImmutable());

        return $progress;
    }

    public function testUnVisiteurNAPasCommence(): void
    {
        $progress = TrackProgress::of($this->track, []);

        $this->assertFalse($progress->hasStarted());
        $this->assertSame(0, $progress->completedCount());
        $this->assertSame(3, $progress->remaining());
        $this->assertSame('e1', $progress->next());
        $this->assertSame('todo', $progress->stateOf('e1'));
        $this->assertSame(0, $progress->xpEarned());
        $this->assertFalse($progress->isComplete());
    }

    public function testLesReussitesSeComptentDansLOrdreDuParcours(): void
    {
        // e2 réussi avant e1 : on reprend quand même à e1. Un exercice retiré du parcours ne compte pas.
        $progress = TrackProgress::of($this->track, ['e1' => $this->draft('e1'), 'e2' => $this->done('e2', 20), 'retire' => $this->done('retire', 99)]);

        $this->assertTrue($progress->hasStarted());
        $this->assertSame(1, $progress->completedCount());
        $this->assertSame(2, $progress->remaining());
        $this->assertSame('e1', $progress->next());
        $this->assertSame('in_progress', $progress->stateOf('e1'));
        $this->assertSame('completed', $progress->stateOf('e2'));
        $this->assertSame(20, $progress->xpEarned());
        $this->assertSame(20, $progress->xpEarnedOn('e2'));
        $this->assertNull($progress->xpEarnedOn('e3'));
    }

    public function testLeRestantParChapitreEtLaFicheDeCours(): void
    {
        $progress = TrackProgress::of($this->track, ['e1' => $this->done('e1', 10), 'e2' => $this->done('e2', 10)]);
        [$un, $deux] = $this->track->chapters;

        $this->assertSame(0, $progress->remainingIn($un));
        $this->assertSame(1, $progress->remainingIn($deux));
        $this->assertSame(['unlocked' => true, 'remaining' => 0], $progress->lesson($un, false));
        $this->assertSame(['unlocked' => false, 'remaining' => 1], $progress->lesson($deux, false));
        $this->assertSame(['unlocked' => true, 'remaining' => 0], $progress->lesson($deux, true), 'Un relecteur la lit toujours.');
        $this->assertSame('e3', $progress->next());
    }

    public function testUnParcoursEntierementReussiEstTermine(): void
    {
        $progress = TrackProgress::of($this->track, ['e1' => $this->done('e1', 10), 'e2' => $this->done('e2', 10), 'e3' => $this->done('e3', 30)]);

        $this->assertTrue($progress->isComplete());
        $this->assertNull($progress->next());
        $this->assertSame(50, $progress->xpEarned());
        $this->assertFalse(TrackProgress::of(new Track('vide', 'p', 'Vide', '', 'symfony-8', [], '/tmp'), [])->isComplete(), 'Un parcours vide n\'est jamais terminé.');
    }
}
