<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Classe gratuite (cohort.free_class) : créée par un formateur sur des exercices de la Pratique (cohort.practice_ids)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cohort ADD free_class BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE cohort ADD practice_ids JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cohort DROP free_class');
        $this->addSql('ALTER TABLE cohort DROP practice_ids');
    }
}
