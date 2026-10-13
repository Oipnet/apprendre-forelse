<?php

namespace App\Share;

use App\Entity\Certificate;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Le partage d'un certificat de réussite : son image (« Certificat de réussite · Ada Lovelace », le parcours, le site)
 * et ses liens, qui mènent à sa page publique, la preuve. Proposé à son titulaire seulement, tant qu'il est valide.
 */
final readonly class CertificateShare
{
    public const string CAMPAIGN = 'parcours';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private ShareImage $images,
    ) {
    }

    public function card(Certificate $certificate): ShareCard
    {
        return new ShareCard('Certificat de réussite · '.$certificate->getHolderName(), $certificate->getTrackTitle(), $this->host());
    }

    /** L'adresse absolue de l'image, versionnée : elle change avec ce qu'elle écrit. */
    public function imageUrl(Certificate $certificate): string
    {
        return $this->urls->generate('app_certificate_image', [
            'code' => $certificate->getCode(),
            'v' => $this->images->version($this->card($certificate)),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** @return array{text: string, linkedin: string, x: string, bluesky: string, lien: string, natif: string} */
    public function links(Certificate $certificate): array
    {
        $count = $certificate->getExerciseCount();

        return ShareLinks::of(
            $this->urls->generate('app_certificate', ['code' => $certificate->getCode()], UrlGeneratorInterface::ABSOLUTE_URL),
            sprintf('J\'ai terminé le parcours « %s » sur %s : %s, chacun validé par des tests.', $certificate->getTrackTitle(), $this->host(), $count > 1 ? $count.' exercices réussis' : '1 exercice réussi'),
            self::CAMPAIGN,
        );
    }

    /** Le site, tel qu'on l'écrit : « apprendre.forelse.fr ». */
    private function host(): string
    {
        $host = (string) parse_url($this->urls->generate('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL), \PHP_URL_HOST);

        return preg_replace('/^www\./', '', $host) ?? $host;
    }
}
