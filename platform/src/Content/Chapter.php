<?php

namespace App\Content;

final readonly class Chapter
{
    /**
     * @param list<string> $exerciseIds dans l'ordre du parcours
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $exerciseIds,
        /** Environnement du chapitre (sinon celui du parcours). */
        public ?string $environment = null,
        /** Fiche de cours de fin de chapitre (markdown de chapters/<id>/lesson.md), null si le chapitre n'en a pas. */
        public ?string $lesson = null,
    ) {
    }

    public function hasLesson(): bool
    {
        return null !== $this->lesson && '' !== trim($this->lesson);
    }

    /**
     * Les liens http(s) de la fiche, dans l'ordre : `[texte](url)`, `<url>` et définitions `[ref]: url`.
     *
     * @return list<string>
     */
    public function lessonLinks(): array
    {
        if (!$this->hasLesson()) {
            return [];
        }
        preg_match_all('#(?:\]\(|<|^\s*\[[^\]]+\]:\s*)(https?://[^\s)<>"\']+)#m', (string) $this->lesson, $matches);

        return array_values(array_unique($matches[1]));
    }
}
