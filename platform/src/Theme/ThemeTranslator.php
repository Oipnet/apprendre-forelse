<?php

namespace App\Theme;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\Formatter\IntlFormatter;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le traducteur du site, qui sert en plus le domaine « theme » : les textes du thème actif (voir ThemeTranslations).
 * Un gabarit de thème écrit donc `{{ 'hero.titre'|trans(domain: 'theme') }}` comme un gabarit du moteur écrit
 * `|trans`, et ses textes suivent la langue de la page.
 *
 * Les fichiers suivent le thème actif et sont relus quand ils changent, sans cache:clear, comme ses gabarits (voir
 * ThemeTemplateLoader) : ils ne passent pas par les catalogues compilés du traducteur de Symfony. Une clé absente dans
 * la langue de la page retombe sur la langue du site, puis sur la clé elle-même, comme |trans.
 */
#[AsDecorator('translator')]
final class ThemeTranslator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, WarmableInterface, ResetInterface
{
    /** @var array{key: string, messages: array<string, array<string, string>>}|null */
    private ?array $loaded = null;

    private readonly IntlFormatter $formatter;

    public function __construct(
        #[AutowireDecorated]
        private readonly TranslatorInterface&TranslatorBagInterface&LocaleAwareInterface $inner,
        private readonly ThemeSelection $selection,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale,
        private readonly ThemeTranslations $files = new ThemeTranslations(),
    ) {
        $this->formatter = new IntlFormatter();
    }

    /** @param array<string, mixed> $parameters */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        if (ThemeTranslations::DOMAIN !== $domain) {
            return $this->inner->trans($id, $parameters, $domain, $locale);
        }
        $locale ??= $this->inner->getLocale();
        $messages = $this->messages();
        foreach (array_unique([$locale, \Locale::getPrimaryLanguage($locale) ?? $locale, $this->defaultLocale]) as $candidate) {
            if (isset($messages[$candidate][$id])) {
                return $this->formatter->formatIntl($messages[$candidate][$id], $candidate, $parameters);
            }
        }

        return $id;
    }

    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        return $this->inner->getCatalogue($locale);
    }

    public function getCatalogues(): array
    {
        return $this->inner->getCatalogues();
    }

    public function setLocale(string $locale): void
    {
        $this->inner->setLocale($locale);
    }

    public function getLocale(): string
    {
        return $this->inner->getLocale();
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        return $this->inner instanceof WarmableInterface ? $this->inner->warmUp($cacheDir, $buildDir) : [];
    }

    /** Relus à la requête suivante si leurs fichiers ont changé, ou si le thème actif a changé. */
    public function reset(): void
    {
        if ($this->inner instanceof ResetInterface) {
            $this->inner->reset();
        }
    }

    /** @return array<string, array<string, string>> */
    private function messages(): array
    {
        $files = $this->files->files($this->selection->directory());
        $key = serialize(array_map(static fn (string $file) => [$file, @filemtime($file), @filesize($file)], $files));
        if ($key !== ($this->loaded['key'] ?? null)) {
            $this->loaded = ['key' => $key, 'messages' => $this->files->load($this->selection->directory())];
        }

        return $this->loaded['messages'];
    }
}
