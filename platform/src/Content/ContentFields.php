<?php

namespace App\Content;

/**
 * Les champs que partagent les fichiers d'un pack (parcours, exercices de Pratique, articles) : visibilité, dates,
 * textes courts. Une même règle, un même message d'erreur, quel que soit le lecteur.
 */
final class ContentFields
{
    /**
     * La visibilité d'un parcours, d'un article ou d'un exercice de Pratique (clé « visibility ») : publique par défaut.
     *
     * @param array<string, mixed> $meta
     */
    public static function visibility(array $meta, string $file): string
    {
        $visibility = (string) ($meta['visibility'] ?? Track::VISIBILITY_PUBLIC);
        if (!\in_array($visibility, Track::VISIBILITIES, true)) {
            throw new ContentException(sprintf('%s : visibilité « %s » inconnue (%s).', $file, $visibility, implode(', ', Track::VISIBILITIES)));
        }

        return $visibility;
    }

    /** Une date AAAA-MM-JJ. YAML lit une date sans guillemets (2026-10-06) comme un horodatage. */
    public static function date(mixed $value, string $file, string $key): \DateTimeImmutable
    {
        return match (true) {
            \is_int($value) => new \DateTimeImmutable('@'.$value),
            \is_string($value) && false !== ($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value)) => $date,
            default => throw new ContentException(sprintf('%s : « %s » doit être une date (AAAA-MM-JJ).', $file, $key)),
        };
    }

    /** Un texte facultatif d'une ligne : une chaîne non vide, sans balise (il finit en meta description). */
    public static function optionalText(mixed $value, string $where): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value) || '' === trim($value) || $value !== strip_tags($value)) {
            throw new ContentException(sprintf('%s est un texte, sans balise HTML.', $where));
        }

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
