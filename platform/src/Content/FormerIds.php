<?php

namespace App\Content;

/**
 * Les anciens identifiants d'un parcours ou d'un exercice (clé « former_ids ») : renommé ou renuméroté, il garde ses
 * adresses, qui redirigent (301) vers la nouvelle (voir FormerIdRedirectListener).
 */
final class FormerIds
{
    /** @return list<string> */
    public static function parse(mixed $value, string $file): array
    {
        if (null === $value) {
            return [];
        }
        if (!\is_array($value) || !array_is_list($value) || [] !== array_filter($value, static fn ($id) => !\is_string($id) || 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $id))) {
            throw new ContentException(sprintf('%s : « former_ids » est une liste d\'anciens identifiants (lettres, chiffres, « . », « _ », « - »).', $file));
        }

        return array_values(array_unique($value));
    }

    /**
     * Vérifie qu'aucun ancien identifiant ne reprend un identifiant actuel ni un autre ancien identifiant : une adresse
     * ne peut mener qu'à un seul endroit.
     *
     * @param array<string, list<string>> $formerIds anciens identifiants, par identifiant actuel
     */
    public static function assertNoCollision(array $formerIds, string $what): void
    {
        $seen = [];
        foreach ($formerIds as $current => $olds) {
            foreach ($olds as $old) {
                if (isset($formerIds[$old])) {
                    throw new ContentException(sprintf('%s « %s » : l\'ancien identifiant « %s » est celui d\'un autre, actuel.', $what, $current, $old));
                }
                if (isset($seen[$old])) {
                    throw new ContentException(sprintf('%s « %s » et « %s » déclarent tous deux l\'ancien identifiant « %s ».', $what, $seen[$old], $current, $old));
                }
                $seen[$old] = $current;
            }
        }
    }
}
