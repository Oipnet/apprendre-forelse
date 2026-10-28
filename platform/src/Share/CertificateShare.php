<?php

namespace App\Share;

use App\Entity\Certificate;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private TranslatorInterface $translator,
        private SiteHost $site,
    ) {
    }

    public function card(Certificate $certificate): ShareCard
    {
        return new ShareCard($this->translator->trans('share.carte.certificat', ['nom' => $certificate->getHolderName()]), $certificate->getTrackTitle(), $this->site->name());
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
            $this->translator->trans('share.texte.certificat', ['parcours' => $certificate->getTrackTitle(), 'site' => $this->site->name(), 'n' => max(1, $count)]),
            self::CAMPAIGN,
        );
    }
}
