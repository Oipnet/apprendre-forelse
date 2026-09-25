<?php

namespace App\Api;

use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Plafond de ce qu'une demande au mentor envoie au modèle : chaque caractère se paie. */
final class MentorInputSize
{
    /** Environ 30 000 jetons : largement de quoi relire un exercice, pas de quoi remplir une facture. */
    public const int MAX_CHARACTERS = 120_000;

    /**
     * Appelé par un Callback, qui passe même quand la contrainte Type('string') des fichiers a échoué : une valeur
     * peut encore ne pas être une chaîne, d'où le is_string().
     *
     * @param array<array-key, mixed> $files
     */
    public static function check(array $files, ExecutionContextInterface $context, string $extra = ''): void
    {
        $total = mb_strlen($extra);
        foreach ($files as $path => $content) {
            $total += mb_strlen((string) $path) + (\is_string($content) ? mb_strlen($content) : 0);
        }
        if ($total > self::MAX_CHARACTERS) {
            $context->buildViolation(sprintf('Trop de code pour le mentor : %d caractères, au plus %d.', $total, self::MAX_CHARACTERS))
                ->atPath('files')
                ->addViolation();
        }
    }
}
