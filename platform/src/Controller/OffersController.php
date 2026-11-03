<?php

namespace App\Controller;

use App\Content\TrackVisibility;
use App\Seo\Page\OffersSeo;
use App\Theme\Theme;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La page des offres : ce que la personne du thème vend de son temps (formations en entreprise, audits), prix
 * affichés, avec un lien de rendez-vous. Elle prolonge la page auteur (qui il est) par ce qu'il apporte à une équipe.
 * Un thème qui ne déclare pas « offers », ou ne le traduit pas dans la langue demandée, n'a pas de page : 404.
 */
final class OffersController extends AbstractController
{
    #[Route(['fr' => '/formations', 'en' => '/en/training'], name: 'app_offers', methods: ['GET'])]
    public function show(Theme $theme, TrackVisibility $tracks, OffersSeo $seo): Response
    {
        $offers = $theme->offers();
        if (null === $offers || !$theme->speaks($tracks->locale())) {
            throw $this->createNotFoundException();
        }
        $seo->page($offers);
        $author = $theme->author();

        return $this->render('offers/show.html.twig', [
            'offers' => $offers,
            // Le rendez-vous en ligne, sinon la page de contact du site.
            'booking' => $offers['booking'] ?? $this->generateUrl('app_contact'),
            'external' => null !== $offers['booking'],
            // Le lien « Qui suis-je ? » : la page auteur ne se répète pas ici.
            'author' => null !== $author && $author['hasPage'] ? $author : null,
        ]);
    }
}
