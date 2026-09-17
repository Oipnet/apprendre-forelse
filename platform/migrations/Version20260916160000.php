<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pratique : progression et retours sans parcours (track_id null), une progression par exercice.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_progress_exercise');
        $this->addSql('ALTER TABLE exercise_progress ALTER track_id DROP NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROGRESS_EXERCISE ON exercise_progress (user_id, track_id, exercise_id) WHERE (track_id IS NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROGRESS_PRACTICE ON exercise_progress (user_id, exercise_id) WHERE (track_id IS NULL)');
        $this->addSql('ALTER TABLE feedback ALTER track_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Sans parcours, une progression ou un retour de Pratique n'a plus de place : on les retire.
        $this->addSql('DELETE FROM exercise_progress WHERE track_id IS NULL');
        $this->addSql('DELETE FROM feedback WHERE track_id IS NULL');
        $this->addSql('DROP INDEX UNIQ_PROGRESS_PRACTICE');
        $this->addSql('DROP INDEX UNIQ_PROGRESS_EXERCISE');
        $this->addSql('ALTER TABLE exercise_progress ALTER track_id SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROGRESS_EXERCISE ON exercise_progress (user_id, track_id, exercise_id)');
        $this->addSql('ALTER TABLE feedback ALTER track_id SET NOT NULL');
    }
}
