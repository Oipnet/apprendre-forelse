<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exercices à étapes (rouge, vert, refacto) : l\'étape où en est l\'apprenant (exercise_progress.step)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercise_progress ADD step SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercise_progress DROP step');
    }
}
