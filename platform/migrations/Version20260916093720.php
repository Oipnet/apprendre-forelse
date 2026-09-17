<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916093720 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chefs de cohorte et parcours disponibles par cohorte (liste vide : tous les parcours, comme avant).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cohort_chef (cohort_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (cohort_id, user_id))');
        $this->addSql('CREATE INDEX IDX_EE26C0B635983C93 ON cohort_chef (cohort_id)');
        $this->addSql('CREATE INDEX IDX_EE26C0B6A76ED395 ON cohort_chef (user_id)');
        $this->addSql('ALTER TABLE cohort_chef ADD CONSTRAINT FK_EE26C0B635983C93 FOREIGN KEY (cohort_id) REFERENCES cohort (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cohort_chef ADD CONSTRAINT FK_EE26C0B6A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cohort ADD available_track_ids JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cohort_chef DROP CONSTRAINT FK_EE26C0B635983C93');
        $this->addSql('ALTER TABLE cohort_chef DROP CONSTRAINT FK_EE26C0B6A76ED395');
        $this->addSql('DROP TABLE cohort_chef');
        $this->addSql('ALTER TABLE cohort DROP available_track_ids');
    }
}
