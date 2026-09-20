<?php

namespace App\Controller;

use App\Entity\Avis;
use App\Repository\BiereRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AvisController extends AbstractController
{
    /**
     * Dépôt d'un avis.
     *
     * Failles :
     *  - F4.1/F4.2 : formulaire écrit à la main, AUCUN jeton CSRF vérifié (alors
     *    que la boutique a déjà le composant Form qui l'aurait posé).
     *  - F3.1/F3.6 : corps, nom d'auteur et site sont enregistrés tels quels et
     *    rendus sans échappement sur la fiche.
     *  - F5.3 : la note n'est pas validée (11/5 accepté).
     */
    #[Route('/biere/{slug}/avis', name: 'app_avis_deposer', methods: ['POST'])]
    public function deposer(string $slug, Request $request, BiereRepository $bieres, EntityManagerInterface $em): Response
    {
        $biere = $bieres->findOneBy(['slug' => $slug]);
        if (null === $biere) {
            throw $this->createNotFoundException();
        }

        $avis = (new Avis())
            ->setBiere($biere)
            ->setAuteurNom((string) $request->request->get('auteurNom', 'Anonyme'))
            ->setNote((int) $request->request->get('note', 5))
            ->setTitre((string) $request->request->get('titre', ''))
            ->setCorps((string) $request->request->get('corps', ''))
            ->setSiteAuteur($request->request->get('siteAuteur') ?: null);

        /** @var \App\Entity\Client|null $client */
        $client = $this->getUser();
        if (null !== $client) {
            $avis->setClient($client);
        }

        $em->persist($avis);
        $em->flush();

        $this->addFlash('success', 'Merci pour votre avis !');

        return $this->redirectToRoute('app_biere', ['slug' => $slug]);
    }
}
