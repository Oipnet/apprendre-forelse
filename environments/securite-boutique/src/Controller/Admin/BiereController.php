<?php

namespace App\Controller\Admin;

use App\Repository\BiereRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// F8.2 : pas de contrôle d'accès.
class BiereController extends AbstractController
{
    #[Route('/admin/bieres', name: 'app_admin_bieres', methods: ['GET'])]
    public function index(BiereRepository $bieres): Response
    {
        return $this->render('admin/bieres.html.twig', ['bieres' => $bieres->findAll()]);
    }

    /**
     * Édition d'une bière, dont son descriptionHtml (rendu ensuite avec
     * {% autoescape false %} sur la fiche — chapitre 3, F3.2).
     */
    #[Route('/admin/biere/{id}/modifier', name: 'app_admin_biere_modifier', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function modifier(int $id, Request $request, BiereRepository $bieres, EntityManagerInterface $em): Response
    {
        $biere = $bieres->find($id);
        if (null === $biere) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $biere->setNom((string) $request->request->get('nom', $biere->getNom()));
            $biere->setDescription((string) $request->request->get('description', $biere->getDescription()));
            $biere->setDescriptionHtml($request->request->get('descriptionHtml') ?: null);
            $biere->setPrix((string) $request->request->get('prix', $biere->getPrix()));
            $biere->setStock((int) $request->request->get('stock', $biere->getStock()));
            $em->flush();
            $this->addFlash('success', 'Bière mise à jour.');

            return $this->redirectToRoute('app_admin_bieres');
        }

        return $this->render('admin/biere_modifier.html.twig', ['biere' => $biere]);
    }
}
