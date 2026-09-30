<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réglages de l\'instance choisis depuis l\'admin (instance_setting) : le thème actif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE instance_setting (value TEXT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_by VARCHAR(180) DEFAULT NULL, name VARCHAR(64) NOT NULL, PRIMARY KEY (name))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE instance_setting');
    }
}
