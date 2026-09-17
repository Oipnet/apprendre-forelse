<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conditions générales de vente : la version acceptée et son heure, enregistrées avec chaque achat.
 */
final class Version20260917104305 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Achats : version des conditions générales de vente acceptée';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase ADD terms_version VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase ADD terms_accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase DROP terms_version');
        $this->addSql('ALTER TABLE purchase DROP terms_accepted_at');
    }
}
