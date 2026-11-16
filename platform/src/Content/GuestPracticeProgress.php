<?php

namespace App\Content;

use Symfony\Component\HttpFoundation\Request;

/**
 * Les exercices de Pratique qu'un visiteur sans compte a réussis : son navigateur les garde (localStorage, clés
 * `formation:null/<id>`), et site.ts en recopie la liste dans un cookie sur la page de la Pratique, pour que le
 * filtre « À faire seulement » tienne d'une page à l'autre de la liste. Le serveur n'en garde rien : le cookie ne
 * sert qu'à trier ce que le visiteur voit, et un cookie trafiqué ne trompe que lui.
 */
final class GuestPracticeProgress
{
    public const string COOKIE = 'pratique_reussis';

    /** Au-delà, le reste est ignoré : la liste n'en compte pas tant, et le cookie doit rester sous 4 Kio. */
    private const int MAX = 200;

    /** @return list<string> */
    public static function completed(Request $request): array
    {
        $ids = explode('.', (string) $request->cookies->get(self::COOKIE, ''));
        $ids = array_filter($ids, static fn (string $id) => 1 === preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $id));

        return \array_slice(array_values(array_unique($ids)), 0, self::MAX);
    }
}
