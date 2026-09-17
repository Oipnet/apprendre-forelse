<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mon compte : adresse confirmée par lien (les comptes existants sont à confirmer), facture Stripe de chaque achat.
 */
final class Version20260917112604 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comptes : adresse confirmée ; achats : facture Stripe';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase ADD stripe_invoice_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD email_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase DROP stripe_invoice_id');
        $this->addSql('ALTER TABLE "user" DROP email_verified_at');
    }
}
