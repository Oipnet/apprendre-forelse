<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adresses email des comptes en minuscules, uniques sans tenir compte de la casse';
    }

    public function up(Schema $schema): void
    {
        // Des comptes qui ne diffèrent que par la casse (« Ada@… » et « ada@… ») : souvent la même personne, inscrite
        // deux fois. Rien n'est fusionné d'office (achats, progression) : on les liste, l'administrateur tranche.
        $duplicates = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT LOWER(email) AS email, STRING_AGG(id || ' (' || email || ')', ', ' ORDER BY id) AS accounts
            FROM "user" GROUP BY LOWER(email) HAVING COUNT(*) > 1 ORDER BY LOWER(email)
            SQL);
        $this->abortIf([] !== $duplicates, sprintf(
            "Des comptes partagent une même adresse email, à la casse près. Pour chaque adresse, gardez un compte et "
            ."changez l'adresse des autres (ou supprimez-les) dans /admin, puis relancez les migrations.\n%s",
            implode("\n", array_map(static fn (array $row): string => sprintf('- %s : comptes %s', $row['email'], $row['accounts']), $duplicates)),
        ));

        $this->addSql('UPDATE "user" SET email = LOWER(email) WHERE email <> LOWER(email)');
        // L'index sur l'expression garantit l'unicité quelle que soit la casse. UNIQ_IDENTIFIER_EMAIL reste : Doctrine
        // ne sait pas décrire un index sur une expression, le mapping (User) garde donc l'index sur la colonne.
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_EMAIL_LOWER ON "user" (LOWER(email))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_USER_EMAIL_LOWER');
    }
}
