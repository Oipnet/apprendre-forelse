<?php

namespace App\Seo;

/**
 * Les balises d'une page fixe, déclarées sur l'action qui la sert : ajouter une page de ce genre ne demande
 * rien d'autre que cet attribut. StaticPageSeo les pose avant l'action ; la canonique est l'adresse de sa route.
 *
 * Une page dont le contenu fait les balises (un parcours, un exercice…) a sa classe dans Seo\Page.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class Seo
{
    /**
     * @param string      $title       sans la marque : « Contact » donne « Contact | <marque> », ou « Contact » s'il est trop long
     * @param string      $description « %marque% » y est remplacé par le nom du site
     * @param string|null $breadcrumb  le nom de la page dans le fil d'Ariane, après l'accueil (null : pas de fil)
     */
    public function __construct(
        public string $title,
        public string $description,
        public ?string $breadcrumb = null,
    ) {
    }
}
