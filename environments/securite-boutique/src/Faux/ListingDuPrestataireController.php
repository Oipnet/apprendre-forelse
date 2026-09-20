<?php

namespace App\Faux;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * FAILLE (chapitre 14, F14.3) : liste le contenu de /uploads/ et /backup/, comme
 * le nginx mal configuré du prestataire. Le bac à sable n'a pas de serveur web qui
 * liste : on le simule. Corriger = supprimer la route ET sortir les fichiers de public/.
 */
final class ListingDuPrestataireController extends AbstractController
{
    #[Route('/uploads/', name: 'app_listing_uploads', methods: ['GET'])]
    public function uploads(): Response
    {
        return $this->lister($this->getParameter('kernel.project_dir').'/public/uploads/etiquettes');
    }

    #[Route('/backup/', name: 'app_listing_backup', methods: ['GET'])]
    public function backup(): Response
    {
        return $this->lister($this->getParameter('kernel.project_dir').'/public/backup');
    }

    private function lister(string $dossier): Response
    {
        $fichiers = is_dir($dossier) ? array_values(array_diff(scandir($dossier) ?: [], ['.', '..'])) : [];
        $liens = array_map(static fn (string $f) => sprintf('<a href="%s">%s</a>', $f, $f), $fichiers);

        return new Response('<h1>Index of</h1><pre>'.implode("\n", $liens).'</pre>');
    }
}
