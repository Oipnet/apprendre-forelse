<?php

namespace App\Seo;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Les balises de la page en cours (title, description, canonical, Open Graph, JSON-LD) : remplies par le contrôleur
 * (voir SeoWriter), rendues par base.html.twig sous le nom `seo`. Une page qui n'en remplit aucune garde son bloc
 * `title` et n'a ni canonical ni Open Graph : c'est le cas des pages privées.
 */
final class PageSeo implements ResetInterface
{
    public const int TITLE_MAX = 60;
    public const int DESCRIPTION_MAX = 155;
    public const string SITE_NAME = 'Forelse';

    private ?string $title = null;
    private ?string $description = null;
    private ?string $canonical = null;
    private ?string $image = null;
    private ?string $imageAlt = null;
    private string $type = 'website';
    /** @var list<array<string, mixed>> */
    private array $structuredData = [];

    public function __construct(
        private readonly RequestStack $requests,
        private readonly SearchIndexing $indexing,
    ) {
    }

    /** Le premier titre qui tient dans la limite ; à défaut, le dernier, raccourci. */
    public function setTitle(string ...$candidates): self
    {
        $candidates = array_map(self::clean(...), $candidates);
        foreach ($candidates as $candidate) {
            if (mb_strlen($candidate) <= self::TITLE_MAX) {
                $this->title = $candidate;

                return $this;
            }
        }
        $this->title = self::shorten((string) end($candidates), self::TITLE_MAX);

        return $this;
    }

    /** Un title déjà mis en forme (marque comprise), rendu tel quel : la page qui le fournit en répond. */
    public function setFullTitle(string $title): self
    {
        $this->title = self::clean($title);

        return $this;
    }

    /**
     * Le texte principal, complété s'il est trop court pour être utile (moins de 120 caractères), le tout dans
     * la limite. Le complément est abandonné plutôt que coupé.
     */
    public function setDescription(string $text, string $complement = ''): self
    {
        $text = self::clean($text);
        $complement = self::clean($complement);
        if (mb_strlen($text) < 120 && '' !== $complement && mb_strlen($text.' '.$complement) <= self::DESCRIPTION_MAX) {
            $text .= ' '.$complement;
        }
        $this->description = self::shorten($text, self::DESCRIPTION_MAX);

        return $this;
    }

    public function setCanonical(string $url): self
    {
        $this->canonical = $url;

        return $this;
    }

    public function setImage(string $url, string $alt): self
    {
        $this->image = $url;
        $this->imageAlt = $alt;

        return $this;
    }

    /** Type Open Graph : « website », ou « article » pour un contenu daté. */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /** @param array<string, mixed> $data un objet schema.org, sans @context (ajouté au rendu) */
    public function addStructuredData(array $data): self
    {
        $this->structuredData[] = ['@context' => 'https://schema.org', ...$data];

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCanonical(): ?string
    {
        return $this->canonical;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function getImageAlt(): ?string
    {
        return $this->imageAlt;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /** @return list<array<string, mixed>> */
    public function getStructuredData(): array
    {
        return $this->structuredData;
    }

    /** La directive robots de la page (null : indexable). Les codes d'erreur sont couverts par l'en-tête X-Robots-Tag. */
    public function getRobots(): ?string
    {
        $request = $this->requests->getMainRequest();

        return null === $request ? null : $this->indexing->directive($request);
    }

    public function reset(): void
    {
        $this->title = $this->description = $this->canonical = $this->image = $this->imageAlt = null;
        $this->type = 'website';
        $this->structuredData = [];
    }

    /** Coupe sur un mot, avec des points de suspension, sans dépasser la longueur. */
    public static function shorten(string $text, int $max): string
    {
        $text = self::clean($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');
        $cut = false === $space ? mb_substr($cut, 0, $max - 1) : mb_substr($cut, 0, $space);

        return preg_replace('/[\s,;:.–(«-]+$/u', '', $cut).'…';
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
