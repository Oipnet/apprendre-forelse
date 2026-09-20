<?php

namespace App\Controller;

use App\Entity\Client;
use App\Repository\ClientRepository;
use App\Security\HachageDuPrestataire;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InscriptionController extends AbstractController
{
    /**
     * Inscription.
     *  - F5.4 : hydratation en masse de l'entité Client. Poster roles[]=ROLE_ADMIN
     *    crée un compte administrateur.
     *  - F14.5 : message d'unicité explicite → oracle d'énumération des comptes.
     */
    #[Route('/inscription', name: 'app_inscription', methods: ['GET', 'POST'])]
    public function inscription(Request $request, ClientRepository $clients, HachageDuPrestataire $hachage, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            $email = (string) $request->request->get('email', '');
            if (null !== $clients->parEmail($email)) {
                $this->addFlash('error', 'Cette adresse est déjà utilisée.');

                return $this->redirectToRoute('app_inscription');
            }

            $client = new Client();
            foreach ($request->request->all() as $champ => $valeur) {
                $setter = 'set'.ucfirst($champ);
                if (method_exists($client, $setter)) {
                    $client->$setter($valeur);
                }
            }
            $client->setMotDePasse($hachage->hacher((string) $request->request->get('motDePasse', '')));

            $em->persist($client);
            $em->flush();
            $this->addFlash('success', 'Compte créé, vous pouvez vous connecter.');

            return $this->redirectToRoute('app_connexion');
        }

        return $this->render('securite/inscription.html.twig');
    }
}
