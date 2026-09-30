<?php

namespace App\Theme;

/** Un thème qu'on ne peut pas activer : introuvable, ou en erreur. Le message dit pourquoi. */
final class ThemeActivationException extends \RuntimeException
{
    /** @param list<string> $errors */
    public static function invalid(string $id, array $errors): self
    {
        return new self(sprintf('Le thème « %s » ne peut pas être activé : %s', $id, implode(' ; ', $errors)));
    }
}
