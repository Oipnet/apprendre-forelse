<?php

namespace App\Theme;

/**
 * Le contrat des gabarits entre le moteur et les thèmes (voir docs/themes.md).
 *
 * Les points de surcharge sont faits pour être remplacés : chacun porte en tête un commentaire « @theme » qui dit ses
 * variables et ce qui est obligatoire. Retirer ou renommer une de leurs variables est un changement majeur. Les autres
 * gabarits se remplacent aussi, mais sans garantie d'une version à l'autre.
 *
 * Les gabarits verrouillés ne se remplacent pas : la page d'exercice et les éditeurs de l'atelier gardent l'habillage
 * du moteur (un thème ne les touche que par sa palette « editor »). ThemeTemplateLoader les ignore.
 */
final class TemplateContract
{
    /** @var list<string> */
    public const array POINTS = [
        'base.html.twig',
        '_header.html.twig',
        '_footer.html.twig',
        'home.html.twig',
        'home/_hero.html.twig',
        'home/_demo.html.twig',
        'home/_promise.html.twig',
        'home/_how.html.twig',
        'home/_showcase.html.twig',
        'home/_tracks.html.twig',
        'home/_track_card.html.twig',
        'home/_ai.html.twig',
        'home/_audience.html.twig',
        'home/_author.html.twig',
        'home/_signup.html.twig',
        'home/_faq.html.twig',
        'blog/_article_card.html.twig',
        'bundles/TwigBundle/Exception/error.html.twig',
    ];

    /** @var list<string> */
    public const array LOCKED = [
        'exercise/play.html.twig',
        'studio/edit.html.twig',
        'studio/lesson.html.twig',
    ];

    public static function isLocked(string $name): bool
    {
        $name = ltrim(str_replace('\\', '/', $name), '/');
        if (str_starts_with($name, '@'.\Twig\Loader\FilesystemLoader::MAIN_NAMESPACE.'/')) {
            $name = substr($name, \strlen(\Twig\Loader\FilesystemLoader::MAIN_NAMESPACE) + 2);
        }

        return \in_array($name, self::LOCKED, true);
    }
}
