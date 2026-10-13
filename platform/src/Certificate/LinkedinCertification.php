<?php

namespace App\Certificate;

use App\Entity\Certificate;
use App\Theme\Theme;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * « Ajouter à mon profil LinkedIn » : le formulaire de certification de LinkedIn, prérempli par l'adresse. Un simple
 * lien, sans API ni autorisation : l'apprenant valide lui-même l'ajout sur LinkedIn. L'organisme est le nom de
 * l'instance (marque blanche).
 */
final readonly class LinkedinCertification
{
    public function __construct(
        private Theme $theme,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function addToProfileUrl(Certificate $certificate): string
    {
        return 'https://www.linkedin.com/profile/add?'.http_build_query([
            'startTask' => 'CERTIFICATION_NAME',
            'name' => $certificate->getTrackTitle(),
            'organizationName' => $this->theme->name(),
            'issueYear' => $certificate->getIssuedAt()->format('Y'),
            'issueMonth' => $certificate->getIssuedAt()->format('n'),
            'certUrl' => $this->urls->generate('app_certificate', ['code' => $certificate->getCode()], UrlGeneratorInterface::ABSOLUTE_URL),
            'certId' => $certificate->getCode(),
        ], '', '&', \PHP_QUERY_RFC3986);
    }
}
