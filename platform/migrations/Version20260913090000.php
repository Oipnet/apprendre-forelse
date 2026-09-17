<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Progression : solution consultée (sans XP) et revue de code du mentor.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercise_progress ADD solution_revealed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE exercise_progress ADD review JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercise_progress DROP solution_revealed');
        $this->addSql('ALTER TABLE exercise_progress DROP review');
    }
}
