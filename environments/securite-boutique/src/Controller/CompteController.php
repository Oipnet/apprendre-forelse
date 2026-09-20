<?php

namespace App\Controller;

use App\Entity\Adresse;
use App\Entity\Client;
use App\Repository\AdresseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class CompteController extends AbstractController
{
    #[Route('/compte', name: 'app_compte', methods: ['GET'])]
    public function tableauDeBord(): Response
    {
        return $this->render('compte/index.html.twig');
    }

    /**
     * FAILLE (chapitre 5, F5.4) : hydratation en masse sur le profil.
     * roles[]=ROLE_ADMIN élève le compte connecté.
     */
    #[Route('/compte/profil', name: 'app_compte_profil', methods: ['GET', 'POST'])]
    public function profil(Request $request, EntityManagerInterface $em): Response
    {
        /** @var Client $client */
        $client = $this->getUser();

        if ($request->isMethod('POST')) {
            foreach ($request->request->all() as $champ => $valeur) {
                $setter = 'set'.ucfirst($champ);
                if (method_exists($client, $setter)) {
                    $client->$setter($valeur);
                }
            }
            $em->flush();
            $this->addFlash('success', 'Profil mis à jour.');

            return $this->redirectToRoute('app_compte_profil');
        }

        return $this->render('compte/profil.html.twig', ['client' => $client]);
    }

    #[Route('/compte/adresses', name: 'app_compte_adresses', methods: ['GET', 'POST'])]
    public function adresses(Request $request, AdresseRepository $adresses, EntityManagerInterface $em): Response
    {
        /** @var Client $client */
        $client = $this->getUser();

        if ($request->isMethod('POST')) {
            $adresse = (new Adresse())
                ->setClient($client)
                ->setLibelle((string) $request->request->get('libelle', 'Adresse'))
                ->setRue((string) $request->request->get('rue', ''))
                ->setCodePostal((string) $request->request->get('codePostal', ''))
                ->setVille((string) $request->request->get('ville', ''));
            $em->persist($adresse);
            $em->flush();

            return $this->redirectToRoute('app_compte_adresses');
        }

        return $this->render('compte/adresses.html.twig', [
            'adresses' => $adresses->findBy(['client' => $client]),
        ]);
    }

    /**
     * FAILLE (chapitre 4, F4.3 — Boss) : suppression en GET, sans jeton. Un simple
     * <img src="/compte/adresse/12/supprimer"> suffit. Correction : POST + jeton CSRF.
     */
    #[Route('/compte/adresse/{id}/supprimer', name: 'app_compte_adresse_supprimer', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function supprimerAdresse(int $id, AdresseRepository $adresses, EntityManagerInterface $em): Response
    {
        $adresse = $adresses->find($id);
        if (null !== $adresse) {
            $em->remove($adresse);
            $em->flush();
        }

        return $this->redirectToRoute('app_compte_adresses');
    }
}
