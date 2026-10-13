<?php

namespace App\Command;

use App\Repository\InstanceSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Change le fuseau d'une instance qui a déjà des données. Les dates sont stockées sans fuseau, à l'heure de
 * APP_TIMEZONE : passer de UTC à Europe/Paris sans rien d'autre décalerait de une à deux heures toutes celles déjà
 * enregistrées. Cette commande les réécrit dans le nouveau fuseau, colonne par colonne, dans une transaction.
 *
 * À lancer une fois, l'application arrêtée, avec le nouveau APP_TIMEZONE déjà dans .env (voir auto-hebergement/README.md,
 * « Changer de fuseau »). Sans --appliquer, elle compte seulement les dates à convertir.
 *
 * Seules les dates des entités sont converties : la file de Messenger stocke les siennes en UTC quel que soit le
 * fuseau, et les dates écrites en texte (historique d'un certificat…) portent leur décalage. Une heure prise dans
 * la nuit du passage à l'heure d'hiver (entre 2 h et 3 h, qui existe deux fois) garde la première des deux.
 */
#[AsCommand(
    name: 'app:fuseau:convertir',
    description: 'Réécrit les dates déjà en base d\'un fuseau à un autre (changement de APP_TIMEZONE).',
)]
final class TimezoneConvertCommand
{
    /** Le fuseau dans lequel les dates sont stockées, une fois converties : une seconde conversion est refusée. */
    public const string SETTING = 'stored_timezone';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InstanceSettingRepository $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Le fuseau des dates enregistrées jusqu\'ici (l\'ancien APP_TIMEZONE, souvent UTC)')] string $from,
        #[Argument('Le nouveau fuseau, déjà dans APP_TIMEZONE (par exemple Europe/Paris)')] string $to,
        #[Option('Convertir pour de bon (sinon, compter seulement)')] bool $appliquer = false,
    ): int {
        foreach ([$from, $to] as $zone) {
            if (!\in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
                $io->error(sprintf('Fuseau inconnu : « %s ».', $zone));

                return Command::FAILURE;
            }
        }
        if ($from === $to) {
            $io->error('Les deux fuseaux sont identiques : rien à convertir.');

            return Command::FAILURE;
        }
        if (date_default_timezone_get() !== $to) {
            $io->error(sprintf('APP_TIMEZONE vaut « %s » : réglez-le d\'abord à « %s », sinon l\'application continuerait d\'écrire dans l\'ancien fuseau.', date_default_timezone_get(), $to));

            return Command::FAILURE;
        }
        if (null !== ($stored = $this->settings->setting(self::SETTING)?->getValue()) && $stored !== $from) {
            $io->error(sprintf('Les dates sont déjà stockées en « %s » : les convertir depuis « %s » les décalerait une seconde fois.', $stored, $from));

            return Command::FAILURE;
        }

        $connection = $this->entityManager->getConnection();
        $columns = $this->columns();
        $rows = [];
        foreach ($columns as [$table, $column]) {
            $count = (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s IS NOT NULL', $connection->quoteSingleIdentifier($table), $connection->quoteSingleIdentifier($column)));
            $rows[] = [$table.'.'.$column, $count];
        }
        $io->table(['Colonne', 'Dates'], $rows);

        if (!$appliquer) {
            $io->note(sprintf('Rien n\'a changé. Relancez avec --appliquer pour convertir ces dates de %s à %s.', $from, $to));

            return Command::SUCCESS;
        }

        $connection->transactional(function () use ($connection, $columns, $from, $to): void {
            foreach ($columns as [$table, $column]) {
                $quoted = $connection->quoteSingleIdentifier($column);
                // Lue comme une heure de $from, puis écrite comme l'heure qu'il était alors à $to.
                $connection->executeStatement(
                    sprintf('UPDATE %1$s SET %2$s = (%2$s AT TIME ZONE :from) AT TIME ZONE :to WHERE %2$s IS NOT NULL', $connection->quoteSingleIdentifier($table), $quoted),
                    ['from' => $from, 'to' => $to],
                );
            }
        });
        $this->settings->change(self::SETTING, $to, $this->clock->now(), 'app:fuseau:convertir');
        $io->success(sprintf('Dates converties de %s à %s.', $from, $to));

        return Command::SUCCESS;
    }

    /** @return list<array{string, string}> table et colonne de chaque date des entités */
    private function columns(): array
    {
        $columns = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach ($metadata->fieldMappings as $mapping) {
                if (\in_array($mapping->type, [Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE], true)) {
                    $columns[] = [$metadata->getTableName(), $mapping->columnName];
                }
            }
        }
        sort($columns);

        return $columns;
    }
}
