<?php

namespace App\Content;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Le sommaire d'un article : chaque intertitre (h2) reçoit une ancre, et la liste de ces ancres s'affiche en tête dès
 * que l'article en compte assez pour qu'on s'y perde. Un lien vers une section se partage (« …#le-mode-sudo »), et
 * Google reprend parfois ces ancres sous le résultat.
 */
final class ArticleOutline
{
    /** En dessous, le sommaire ne ferait que répéter ce que le lecteur voit déjà. */
    public const int MIN_SECTIONS = 3;

    /**
     * @param string $html le HTML de l'article (LessonRenderer), dont les h2 n'ont pas d'attribut
     *
     * @return array{html: string, sections: list<array{id: string, title: string}>} le HTML aux h2 ancrés, et le
     *                                                                                sommaire (vide sous MIN_SECTIONS)
     */
    public static function of(string $html): array
    {
        $slugger = new AsciiSlugger('fr');
        $sections = [];
        $used = [];
        $html = (string) preg_replace_callback('#<h2>(.*?)</h2>#s', static function (array $m) use ($slugger, &$sections, &$used): string {
            $title = trim(html_entity_decode(strip_tags($m[1]), \ENT_QUOTES | \ENT_HTML5));
            $id = $slugger->slug($title)->lower()->toString() ?: 'section';
            // Deux intertitres identiques : le second prend un numéro, chaque ancre reste unique dans la page.
            $base = $id;
            for ($n = 2; isset($used[$id]); ++$n) {
                $id = $base.'-'.$n;
            }
            $used[$id] = true;
            $sections[] = ['id' => $id, 'title' => $title];

            return sprintf('<h2 id="%s">%s</h2>', $id, $m[1]);
        }, $html);

        return ['html' => $html, 'sections' => \count($sections) >= self::MIN_SECTIONS ? $sections : []];
    }
}
