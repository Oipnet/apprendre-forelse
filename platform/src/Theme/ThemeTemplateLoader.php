<?php

namespace App\Theme;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Loader\FilesystemLoader;

/**
 * Les gabarits du thème actif : ce que <thème>/templates/ contient remplace le gabarit de même nom dans le moteur
 * (`home.html.twig`, `_footer.html.twig`, une page légale…). Consulté avant celui du moteur, il n'a besoin de
 * contenir que les fichiers à remplacer.
 *
 * C'est l'échappatoire du niveau « habillage » : ce que theme.yaml ne règle pas, une instance le
 * réécrit sans toucher au moteur — donc sans fork, et sans rien à republier au titre de l'AGPL.
 *
 * Le dossier suit le thème actif (voir ActiveTheme) : il est relu à chaque requête, et une bascule de thème ou un
 * aperçu se voient sans redémarrer. Le cache compilé de Twig est indexé par le chemin du fichier : deux thèmes ne se
 * mélangent pas. Sans thème, ce chargeur ne connaît aucun chemin : il répond « inconnu » à tout et la chaîne passe
 * au chargeur du moteur.
 *
 * Un fichier ajouté à un thème après coup demande, en production, un redémarrage du conteneur (ou un cache:clear) :
 * Twig ne revérifie pas la fraîcheur des gabarits compilés.
 */
#[AutoconfigureTag('twig.loader', ['priority' => 10])]
final class ThemeTemplateLoader extends FilesystemLoader implements ResetInterface
{
    public const string SUBDIRECTORY = 'templates';

    /** Les gabarits du thème de cette requête : null tant qu'ils n'ont pas été cherchés. */
    private ?string $current = null;

    public function __construct(
        private readonly ThemeSelection $selection,
    ) {
        parent::__construct([]);
    }

    /** exists() regarde ce que le chargeur a déjà trouvé avant de chercher : il doit d'abord suivre le thème actif. */
    public function exists(string $name): bool
    {
        $this->follow();

        return parent::exists($name);
    }

    protected function findTemplate(string $name, bool $throw = true): ?string
    {
        $this->follow();

        return parent::findTemplate($name, $throw);
    }

    /** Oublié entre deux requêtes (mode worker) : la suivante relit le thème actif. */
    public function reset(): void
    {
        $this->current = null;
    }

    private function follow(): void
    {
        $directory = $this->selection->directory();
        $templates = '' === $directory ? '' : rtrim($directory, '/').'/'.self::SUBDIRECTORY;
        if ($templates === $this->current) {
            return;
        }
        $this->current = $templates;
        $this->setPaths('' !== $templates && is_dir($templates) ? [$templates] : []);
        // setPaths() ne vide pas ce que le chargeur a déjà trouvé (ou pas) : sans cela, un gabarit de l'ancien thème
        // resterait servi après la bascule.
        $this->cache = $this->errorCache = [];
    }
}
