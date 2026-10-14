<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Langue de l\'exercice réussi (exercise_progress.submitted_locale) : une traduction se vérifie avec ses propres tests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercise_progress ADD submitted_locale VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercise_progress DROP submitted_locale');
    }
}
