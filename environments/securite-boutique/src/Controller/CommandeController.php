<?php

namespace App\Controller;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Repository\BiereRepository;
use App\Repository\CommandeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class CommandeController extends AbstractController
{
    /**
     * Validation du panier en commande.
     *
     * Failles :
     *  - F5.1 : hydratation en masse. Toute clé POST dont il existe un setter est
     *    appliquée à la commande, y compris « total ». Poster total=0 → total 0.
     *  - F5.2 : le prix unitaire des lignes vient du formulaire (prix_<id>), pas
     *    du catalogue.
     *  - F5.3 : aucune contrainte sur la quantité (négatif accepté).
     */
    #[Route('/commande/valider', name: 'app_commande_valider', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function valider(Request $request, BiereRepository $bieres, EntityManagerInterface $em): Response
    {
        /** @var \App\Entity\Client $client */
        $client = $this->getUser();

        $commande = (new Commande())
            ->setClient($client)
            ->setReference('CMD-'.date('Y').'-'.random_int(1000, 9999))
            ->setStatut('validee')
            ->setCreeLe(new \DateTimeImmutable());

        // F5.1 : hydratation en masse — toute clé POST scalaire disposant d'un setter,
        // « total » compris. Poster total=0 fixe le total à 0.
        foreach ($request->request->all() as $champ => $valeur) {
            $setter = 'set'.ucfirst($champ);
            if (\is_scalar($valeur) && method_exists($commande, $setter)) {
                $commande->$setter((string) $valeur);
            }
        }

        // F5.2/F5.3 : les lignes viennent du formulaire (slug, quantité ET prix).
        foreach ($request->request->all('lignes') as $ligneData) {
            $biere = $bieres->findOneBy(['slug' => $ligneData['slug'] ?? '']);
            if (!$biere) {
                continue;
            }
            $ligne = (new LigneCommande())
                ->setBiere($biere)
                ->setQuantite((int) ($ligneData['quantite'] ?? 1))
                ->setPrixUnitaire((string) ($ligneData['prix'] ?? $biere->getPrix()));
            $commande->ajouterLigne($ligne);
            $em->persist($ligne);
        }

        $em->persist($commande);
        $em->flush();
        $request->getSession()->remove('panier');

        return $this->redirectToRoute('app_commande', ['id' => $commande->getId()]);
    }

    /**
     * FAILLE (chapitre 8, F8.1 — IDOR) : aucune vérification de propriété.
     * Connecté, visiter /commande/1 … /commande/64 affiche les commandes d'autrui.
     * Correction : un CommandeVoter (COMMANDE_VOIR) + #[IsGranted], et un 404
     * plutôt qu'un 403 pour ne pas confirmer l'existence.
     */
    #[Route('/commande/{id}', name: 'app_commande', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function afficher(int $id, CommandeRepository $commandes): Response
    {
        $commande = $commandes->find($id);
        if (null === $commande) {
            throw $this->createNotFoundException();
        }

        return $this->render('boutique/commande.html.twig', ['commande' => $commande]);
    }

    /**
     * FAILLE (chapitre 8, F8.4 — Boss) : la facture est servie sans voter, sur
     * un contrôleur séparé, donc échappe aussi au contrôle de propriété.
     */
    #[Route('/commande/{id}/facture.pdf', name: 'app_commande_facture', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function facture(int $id, CommandeRepository $commandes): Response
    {
        $commande = $commandes->find($id);
        if (null === $commande) {
            throw $this->createNotFoundException();
        }

        return $this->render('boutique/facture.html.twig', ['commande' => $commande]);
    }

    #[Route('/mes-commandes', name: 'app_mes_commandes', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function mesCommandes(CommandeRepository $commandes): Response
    {
        /** @var \App\Entity\Client $client */
        $client = $this->getUser();

        return $this->render('boutique/mes_commandes.html.twig', [
            'commandes' => $commandes->pourClient((int) $client->getId()),
        ]);
    }
}
