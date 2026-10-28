<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clé des cookies « Rester connecté » (user.remember_key), une par compte, pour les annuler même sans mot de passe';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD remember_key VARCHAR(32) DEFAULT NULL');
        // Une clé différente par compte : les cookies déjà donnés deviennent invalides une fois, au déploiement.
        $this->addSql('UPDATE "user" SET remember_key = md5(random()::text || id::text)');
        $this->addSql('ALTER TABLE "user" ALTER remember_key SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP remember_key');
    }
}
