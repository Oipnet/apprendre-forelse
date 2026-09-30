<?php

namespace App\Theme;

use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Source;

/**
 * L'état d'un thème installé, avant de l'activer ou de le prévisualiser : son theme.yaml (couleurs, fichiers déclarés,
 * sections de l'accueil) et ses gabarits, qui doivent au moins se compiler. Un thème en erreur ne s'active pas : il
 * casserait le site de tout le monde.
 */
final readonly class ThemeChecker
{
    public function __construct(
        private ThemeCatalog $catalog,
        private Environment $twig,
        private ThemeLoader $loader = new ThemeLoader(),
    ) {
    }

    /**
     * Ce qui empêche le thème d'être servi ; vide quand il est valable.
     *
     * @return list<string>
     */
    public function errors(string $id): array
    {
        return $this->inspect($id)['errors'];
    }

    /**
     * Ce qu'en montre l'admin : sa configuration quand elle est valable, les gabarits qu'il remplace, ses erreurs.
     *
     * @return array{id: string, directory: string, config: ThemeConfig|null, templates: list<string>, errors: list<string>}
     */
    public function inspect(string $id): array
    {
        $directory = $this->catalog->directory($id);
        if (null === $directory) {
            return ['id' => $id, 'directory' => '', 'config' => null, 'templates' => [], 'errors' => [sprintf('Thème « %s » introuvable.', $id)]];
        }

        $errors = [];
        $config = null;
        try {
            $config = $this->loader->load($directory);
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        $templates = [];
        foreach ($this->templates($directory) as $name => $path) {
            $templates[] = $name;
            try {
                $this->twig->parse($this->twig->tokenize(new Source((string) file_get_contents($path), $name, $path)));
            } catch (TwigError $e) {
                $errors[] = sprintf('Gabarit %s : %s', $name, $e->getRawMessage());
            }
        }

        return ['id' => $id, 'directory' => $directory, 'config' => $config, 'templates' => $templates, 'errors' => $errors];
    }

    /** @return array<string, string> nom du gabarit (« home.html.twig ») => chemin */
    private function templates(string $directory): array
    {
        $root = $directory.'/'.ThemeTemplateLoader::SUBDIRECTORY;
        if ('' === $directory || !is_dir($root)) {
            return [];
        }
        $templates = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                $templates[substr($file->getPathname(), \strlen($root) + 1)] = $file->getPathname();
            }
        }
        ksort($templates);

        return $templates;
    }
}
