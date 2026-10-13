<?php

namespace App\Theme;

use Symfony\Component\Translation\Formatter\IntlFormatter;
use Symfony\Component\Translation\Exception\InvalidArgumentException as InvalidMessageException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Les textes d'un thème : `<thème>/translations/theme.<langue>.yaml`, au format ICU comme ceux du moteur (voir
 * platform/translations/README.md). Ses gabarits les lisent avec `{{ 'hero.titre'|trans(domain: 'theme') }}`, ou
 * `{% trans_default_domain 'theme' %}` (voir ThemeTranslator).
 *
 * Les clés s'imbriquent en YAML (`hero: {titre: …}` donne `hero.titre`). Une erreur de fichier est bruyante, comme
 * celles de theme.yaml : YAML invalide, valeur qui n'est pas un texte, message ICU mal formé.
 */
final class ThemeTranslations
{
    public const string SUBDIRECTORY = 'translations';
    public const string DOMAIN = 'theme';

    /**
     * Les messages du thème, par langue.
     *
     * @return array<string, array<string, string>>
     */
    public function load(string $directory): array
    {
        $messages = [];
        foreach ($this->files($directory) as $locale => $file) {
            try {
                $data = Yaml::parseFile($file);
            } catch (ParseException $e) {
                throw new \RuntimeException(sprintf('%s : YAML invalide, %s', $file, lcfirst($e->getMessage())), previous: $e);
            }
            if (null === $data) {
                continue;
            }
            if (!\is_array($data)) {
                throw new \RuntimeException(sprintf('%s : des clés et leurs textes sont attendus.', $file));
            }
            $messages[$locale] = self::flatten($data, '', $file);
            $formatter = new IntlFormatter();
            foreach ($messages[$locale] as $key => $message) {
                try {
                    $formatter->formatIntl($message, $locale, []);
                } catch (InvalidMessageException $e) {
                    throw new \RuntimeException(sprintf('%s : « %s » n\'est pas un message ICU valable (%s).', $file, $key, $e->getMessage()), previous: $e);
                }
            }
        }

        return $messages;
    }

    /**
     * Les fichiers du thème, par langue, et leur empreinte : de quoi savoir s'il faut les relire.
     *
     * @return array<string, string> langue => chemin
     */
    public function files(string $directory): array
    {
        if ('' === $directory) {
            return [];
        }
        $files = [];
        foreach (glob($directory.'/'.self::SUBDIRECTORY.'/'.self::DOMAIN.'.*.yaml') ?: [] as $file) {
            if (preg_match('/^'.self::DOMAIN.'\.([a-z]{2}(?:_[A-Z]{2})?)\.yaml$/', basename($file), $m)) {
                $files[$m[1]] = $file;
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix, string $file): array
    {
        $messages = [];
        foreach ($data as $key => $value) {
            $id = $prefix.$key;
            if (\is_array($value)) {
                $messages += self::flatten($value, $id.'.', $file);
            } elseif (\is_string($value) || \is_int($value) || \is_float($value)) {
                $messages[$id] = (string) $value;
            } else {
                throw new \RuntimeException(sprintf('%s : « %s » doit être un texte.', $file, $id));
            }
        }

        return $messages;
    }
}
