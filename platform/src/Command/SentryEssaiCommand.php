<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Vérifie le suivi des erreurs de bout en bout : l'exception levée ici doit apparaître dans Sentry avec sa pile.
 */
#[AsCommand(
    name: 'app:sentry:essai',
    description: 'Lève une erreur volontaire, pour vérifier qu\'elle arrive dans Sentry (SENTRY_DSN).',
)]
final class SentryEssaiCommand
{
    public function __invoke(): int
    {
        throw new \RuntimeException('Erreur volontaire (app:sentry:essai) : le suivi des erreurs fonctionne.');
    }
}
