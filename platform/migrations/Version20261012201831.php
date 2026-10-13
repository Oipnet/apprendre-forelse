<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012201831 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Classement (choix de l\'apprenant, exclusion par l\'admin) et vérification serveur des réussites (code figé, verdict)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercise_progress ADD submitted_files JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE exercise_progress ADD verification VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE exercise_progress ADD verification_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE exercise_progress ADD verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE exercise_progress ADD verification_detail VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD leaderboard_opt_in BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD leaderboard_excluded BOOLEAN DEFAULT false NOT NULL');
        // Réussites déjà enregistrées : leur dernier brouillon est le code qui a réussi (personne n'y retouche ensuite, ou
        // presque) ; elles passent au verificateur comme les nouvelles.
        $this->addSql("UPDATE exercise_progress SET submitted_files = files, verification = CASE WHEN files::text IN ('[]', '{}') THEN 'unverifiable' ELSE 'pending' END WHERE status = 'completed'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercise_progress DROP submitted_files');
        $this->addSql('ALTER TABLE exercise_progress DROP verification');
        $this->addSql('ALTER TABLE exercise_progress DROP verification_requested_at');
        $this->addSql('ALTER TABLE exercise_progress DROP verified_at');
        $this->addSql('ALTER TABLE exercise_progress DROP verification_detail');
        $this->addSql('ALTER TABLE "user" DROP leaderboard_opt_in');
        $this->addSql('ALTER TABLE "user" DROP leaderboard_excluded');
    }
}
