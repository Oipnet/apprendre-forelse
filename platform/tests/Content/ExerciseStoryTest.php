<?php

namespace App\Tests\Content;

use App\Content\ExerciseStory;
use App\Content\LessonRenderer;
use PHPUnit\Framework\TestCase;

final class ExerciseStoryTest extends TestCase
{
    public function testLePremierVraiParagrapheSauteLeTitre(): void
    {
        $story = (new ExerciseStory(new LessonRenderer()))->fromInstructions("# Le titre\n\nGorm voudrait que la taverne affiche ses horaires d'ouverture.\n\nLa suite.");

        $this->assertSame("Gorm voudrait que la taverne affiche ses horaires d'ouverture.", $story);
    }

    public function testUnParagrapheQuiAnnonceUneListeGardeSonPremierElement(): void
    {
        $story = (new ExerciseStory(new LessonRenderer()))->fromInstructions("Pipin a voulu « optimiser la volière ». Depuis, Gorm reçoit des plaintes :\n\n- les pigeons repartent sans message ;\n- le registre est vide.");

        $this->assertSame('Pipin a voulu « optimiser la volière ». Depuis, Gorm reçoit des plaintes : les pigeons repartent sans message ;', $story);
    }
}
