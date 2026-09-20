<?php

namespace App\Controller\Admin;

use App\Repository\CommandeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

// F8.2 : pas de contrôle d'accès.
class CommandeController extends AbstractController
{
    #[Route('/admin/commandes', name: 'app_admin_commandes', methods: ['GET'])]
    public function index(Request $request, CommandeRepository $commandes): Response
    {
        $reference = $request->query->get('reference');
        /** @var list<string> $statuts */
        $statuts = (array) $request->query->all('statut');

        // F2.3 : filtrer() concatène référence et statuts dans le DQL.
        $resultats = (null !== $reference || $statuts)
            ? $commandes->filtrer($reference, $statuts)
            : $commandes->toutes();

        return $this->render('admin/commandes.html.twig', [
            'commandes' => $resultats,
            'reference' => $reference,
        ]);
    }

    /**
     * FAILLE (chapitre 8, F8.4 — Boss) : export CSV de TOUTES les commandes,
     * par requête directe au repository, sans filtrer selon les droits.
     */
    #[Route('/admin/commandes/export.csv', name: 'app_export_commandes', methods: ['GET'])]
    public function exporter(CommandeRepository $commandes): StreamedResponse
    {
        $reponse = new StreamedResponse(function () use ($commandes): void {
            $sortie = fopen('php://output', 'wb');
            fputcsv($sortie, ['reference', 'client', 'email', 'total', 'statut'], ',', '"', '\\');
            foreach ($commandes->toutes() as $commande) {
                fputcsv($sortie, [
                    $commande->getReference(),
                    $commande->getClient()?->getNom(),
                    $commande->getClient()?->getEmail(),
                    $commande->getTotal(),
                    $commande->getStatut(),
                ], ',', '"', '\\');
            }
            fclose($sortie);
        });
        $reponse->headers->set('Content-Type', 'text/csv');
        $reponse->headers->set('Content-Disposition', 'attachment; filename="commandes.csv"');

        return $reponse;
    }
}
