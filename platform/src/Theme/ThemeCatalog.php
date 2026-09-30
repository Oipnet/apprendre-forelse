<?php

namespace App\Theme;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Les thèmes installés sur l'instance, parmi lesquels l'admin choisit (voir ActiveTheme) :
 *
 * - « default », le thème du moteur, toujours là ;
 * - « instance », le dossier BRANDING_DIR d'avant les thèmes multiples, s'il contient un theme.yaml (ou l'ancien
 *   marque.yaml) : une instance 2.x garde ainsi son habillage sans rien changer ;
 * - un thème par sous-dossier de THEMES_DIR, nommé par son dossier.
 *
 * Un sous-dossier au nom invalide ou réservé, sans theme.yaml, ou qui sort de THEMES_DIR par un lien symbolique
 * n'est pas un thème : il est ignoré, et l'admin dit pourquoi.
 */
final readonly class ThemeCatalog
{
    public const string DEFAULT = 'default';

    public const string INSTANCE = 'instance';

    /** Le nom d'un dossier de thème : il devient une partie des URL (/theme/<thème>/…). */
    public const string PATTERN = '/^[a-z0-9-]+$/';

    public function __construct(
        #[Autowire(env: 'resolve:THEMES_DIR')]
        private string $themesDirectory,
        #[Autowire(env: 'resolve:BRANDING_DIR')]
        private string $brandingDirectory,
    ) {
    }

    /** Le dossier d'un thème installé (vide pour « default »), ou null s'il n'y en a pas sous ce nom. */
    public function directory(string $id): ?string
    {
        if (self::DEFAULT === $id) {
            return '';
        }
        if (self::INSTANCE === $id) {
            return self::hasThemeFile($this->brandingDirectory) ? $this->brandingDirectory : null;
        }
        if (!preg_match(self::PATTERN, $id)) {
            return null;
        }

        return null === $this->problem($id) ? (string) realpath($this->themesDirectory.'/'.$id) : null;
    }

    public function has(string $id): bool
    {
        return null !== $this->directory($id);
    }

    /**
     * Les thèmes installés, « default » d'abord.
     *
     * @return array<string, string> identifiant => dossier
     */
    public function all(): array
    {
        $themes = [self::DEFAULT => ''];
        if (self::hasThemeFile($this->brandingDirectory)) {
            $themes[self::INSTANCE] = $this->brandingDirectory;
        }
        foreach ($this->folders() as $name) {
            if (null === $this->problem($name)) {
                $themes[$name] = (string) realpath($this->themesDirectory.'/'.$name);
            }
        }

        return $themes;
    }

    /**
     * Les sous-dossiers de THEMES_DIR qui ne sont pas des thèmes, et pourquoi.
     *
     * @return array<string, string> nom du dossier => raison
     */
    public function ignored(): array
    {
        $ignored = [];
        foreach ($this->folders() as $name) {
            if (null !== $problem = $this->problem($name)) {
                $ignored[$name] = $problem;
            }
        }

        return $ignored;
    }

    public function themesDirectory(): string
    {
        return $this->themesDirectory;
    }

    /** @return list<string> les sous-dossiers de THEMES_DIR, triés, sans les dossiers cachés */
    private function folders(): array
    {
        if ('' === $this->themesDirectory || !is_dir($this->themesDirectory)) {
            return [];
        }
        $names = [];
        foreach (scandir($this->themesDirectory) ?: [] as $name) {
            if (!str_starts_with($name, '.') && is_dir($this->themesDirectory.'/'.$name)) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }

    /** Pourquoi ce sous-dossier de THEMES_DIR n'est pas un thème, ou null s'il en est un. */
    private function problem(string $name): ?string
    {
        if ('' === $this->themesDirectory) {
            return 'THEMES_DIR n\'est pas défini';
        }
        if (!preg_match(self::PATTERN, $name)) {
            return 'nom invalide : minuscules, chiffres et tirets seulement';
        }
        if (\in_array($name, [self::DEFAULT, self::INSTANCE], true)) {
            return 'nom réservé';
        }
        $root = realpath($this->themesDirectory);
        $path = realpath($this->themesDirectory.'/'.$name);
        if (false === $root || false === $path || !is_dir($path)) {
            return 'dossier introuvable';
        }
        if (\dirname($path) !== $root) {
            return 'lien symbolique qui sort de THEMES_DIR';
        }
        if (!self::hasThemeFile($path)) {
            return 'pas de '.ThemeLoader::FILE;
        }

        return null;
    }

    private static function hasThemeFile(string $directory): bool
    {
        return '' !== $directory && (is_file($directory.'/'.ThemeLoader::FILE) || is_file($directory.'/'.ThemeLoader::LEGACY_FILE));
    }
}
