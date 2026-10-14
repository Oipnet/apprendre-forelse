<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Langue de l\'achat (purchase.locale) : celle de la page Stripe et de l\'email de confirmation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase ADD locale VARCHAR(5) DEFAULT \'fr\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase DROP locale');
    }
}
