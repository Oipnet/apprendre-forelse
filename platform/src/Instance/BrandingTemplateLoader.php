<?php

namespace App\Instance;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Loader\FilesystemLoader;

/**
 * Les gabarits de l'instance : ce que BRANDING_DIR/templates/ contient remplace le gabarit de même nom
 * dans le moteur (`home.html.twig`, `_footer.html.twig`, une page légale…). Consulté avant celui du
 * moteur, il n'a besoin de contenir que les fichiers à remplacer.
 *
 * C'est l'échappatoire du niveau « habillage » : ce que marque.yaml ne règle pas, une instance le
 * réécrit sans toucher au moteur — donc sans fork, et sans rien à republier au titre de l'AGPL.
 *
 * Sans dossier, ce chargeur ne connaît aucun chemin : il répond « inconnu » à tout et la chaîne passe
 * au chargeur du moteur. En production, les gabarits compilés sont mis en cache : un fichier déposé
 * ici après coup demande un redémarrage du conteneur (ou un cache:clear).
 */
#[AutoconfigureTag('twig.loader', ['priority' => 10])]
final class BrandingTemplateLoader extends FilesystemLoader
{
    public const string SUBDIRECTORY = 'templates';

    public function __construct(
        #[Autowire(env: 'resolve:BRANDING_DIR')]
        string $directory,
    ) {
        $templates = rtrim($directory, '/').'/'.self::SUBDIRECTORY;
        parent::__construct('' !== $directory && is_dir($templates) ? [$templates] : []);
    }
}
