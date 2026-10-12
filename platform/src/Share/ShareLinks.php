<?php

namespace App\Share;

/**
 * Les liens de partage d'une page : LinkedIn, X, Bluesky, le lien à copier et le partage du téléphone.
 *
 * De simples adresses, ouvertes par le visiteur : aucun script ni cookie de ces réseaux sur le site. Chaque lien
 * porte d'où vient la visite (utm_source), qu'elle vient d'un partage (utm_medium) et ce qui a été partagé
 * (utm_campaign : « exercice », « parcours »…), qu'Umami lit sans réglage.
 */
final class ShareLinks
{
    public const string MEDIUM = 'partage';

    /**
     * @param string $url      l'adresse canonique de la page partagée
     * @param string $text     le texte proposé avec le lien (X et Bluesky le préremplissent ; LinkedIn ne lit que l'adresse)
     * @param string $campaign ce qui est partagé : « exercice », « parcours »…
     *
     * @return array{text: string, linkedin: string, x: string, bluesky: string, lien: string, natif: string}
     */
    public static function of(string $url, string $text, string $campaign): array
    {
        $tracked = static fn (string $source) => $url.(str_contains($url, '?') ? '&' : '?').http_build_query([
            'utm_source' => $source,
            'utm_medium' => self::MEDIUM,
            'utm_campaign' => $campaign,
        ]);

        return [
            'text' => $text,
            'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?'.http_build_query(['url' => $tracked('linkedin')], encoding_type: \PHP_QUERY_RFC3986),
            'x' => 'https://x.com/intent/post?'.http_build_query(['text' => $text, 'url' => $tracked('x')], encoding_type: \PHP_QUERY_RFC3986),
            // Bluesky n'a pas de paramètre pour l'adresse : elle suit le texte, et devient une carte d'aperçu.
            'bluesky' => 'https://bsky.app/intent/compose?'.http_build_query(['text' => $text.' '.$tracked('bluesky')], encoding_type: \PHP_QUERY_RFC3986),
            'lien' => $tracked('lien'),
            'natif' => $tracked('natif'),
        ];
    }
}
