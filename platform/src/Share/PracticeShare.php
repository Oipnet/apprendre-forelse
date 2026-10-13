<?php

namespace App\Share;

use App\Content\Framework\FrameworkRegistry;
use App\Content\Practice;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Le partage d'un exercice de la Pratique : son image (« Pratique · Symfony 8.2 », le titre, le site) et ses liens,
 * pour inviter à le faire ou pour annoncer l'avoir réussi.
 */
final readonly class PracticeShare
{
    public const string CAMPAIGN = 'exercice';

    public function __construct(
        private FrameworkRegistry $frameworks,
        private UrlGeneratorInterface $urls,
        private ShareImage $images,
    ) {
    }

    public function card(Practice $practice): ShareCard
    {
        return new ShareCard('Pratique · '.$this->framework($practice), $practice->exercise->title, $this->host());
    }

    /** L'adresse absolue de l'image, versionnée : elle change avec ce qu'elle écrit. */
    public function imageUrl(Practice $practice): string
    {
        return $this->urls->generate('app_practice_image', [
            'exerciseId' => $practice->exercise->id,
            'v' => $this->images->version($this->card($practice)),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Les liens de partage de l'exercice : une invitation à le faire, ou, après la réussite, l'annonce de l'avoir fait.
     *
     * @return array{text: string, linkedin: string, x: string, bluesky: string, lien: string, natif: string}
     */
    public function links(Practice $practice, bool $completed = false): array
    {
        $title = $practice->exercise->title;
        $framework = $this->framework($practice);
        $text = $completed
            ? sprintf('J\'ai réussi l\'exercice « %s » (%s), directement dans le navigateur.', $title, $framework)
            : sprintf('%s : « %s », un exercice court à faire dans le navigateur.', $framework, $title);

        return ShareLinks::of(
            $this->urls->generate('app_exercise_pratique', ['exerciseId' => $practice->exercise->id], UrlGeneratorInterface::ABSOLUTE_URL),
            $text,
            self::CAMPAIGN,
        );
    }

    /** « Symfony 8.2 », ou « Docker » pour un exercice qui n'est pas une nouveauté de version. */
    private function framework(Practice $practice): string
    {
        return trim($this->frameworks->labelOf($practice->framework).' '.$practice->version);
    }

    /** Le site, tel qu'on l'écrit : « apprendre.forelse.fr ». */
    private function host(): string
    {
        $host = (string) parse_url($this->urls->generate('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL), \PHP_URL_HOST);

        return preg_replace('/^www\./', '', $host) ?? $host;
    }
}
