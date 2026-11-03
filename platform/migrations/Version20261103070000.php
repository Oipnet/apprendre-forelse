<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Liens courts du bac à sable (scratch_share) : le code partagé gardé sous un identifiant court, jusqu\'à sa date d\'expiration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE scratch_share (id VARCHAR(16) NOT NULL, framework VARCHAR(16) NOT NULL, code TEXT NOT NULL, delete_key_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_SCRATCH_SHARE_EXPIRES ON scratch_share (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scratch_share');
    }
}
