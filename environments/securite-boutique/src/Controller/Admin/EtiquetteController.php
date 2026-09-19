<?php

namespace App\Controller\Admin;

use App\Entity\Etiquette;
use App\Repository\EtiquetteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// F8.2 : pas de contrôle d'accès.
class EtiquetteController extends AbstractController
{
    public function __construct(
        #[Autowire('%dossier_etiquettes%')]
        private readonly string $dossierEtiquettes,
    ) {
    }

    #[Route('/admin/etiquettes', name: 'app_admin_etiquettes', methods: ['GET', 'POST'])]
    public function index(Request $request, EtiquetteRepository $etiquettes, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            /** @var UploadedFile|null $fichier */
            $fichier = $request->files->get('etiquette');
            if (null !== $fichier) {
                $this->deposer($fichier, $em);
                $this->addFlash('success', 'Étiquette déposée.');
            }

            return $this->redirectToRoute('app_admin_etiquettes');
        }

        return $this->render('admin/etiquettes.html.twig', ['etiquettes' => $etiquettes->findAll()]);
    }

    /**
     * Failles réunies (chapitre 6) :
     *  - F6.1 : le type est vérifié par l'extension (pathinfo) et le typeMime est
     *    celui annoncé par le navigateur (getClientMimeType) — tous deux falsifiables.
     *    « etiquette-2026.php.jpg » passe le filtre.
     *  - F6.2 : le fichier est écrit dans public/uploads/etiquettes/ sous son nom
     *    d'origine → servi ET interprété par son URL.
     *  - F6.3 : aucune taille maximale, aucun #[Assert\File].
     */
    private function deposer(UploadedFile $fichier, EntityManagerInterface $em): void
    {
        $nom = $fichier->getClientOriginalName();
        $extension = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
        if (!\in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            $this->addFlash('error', "Format refusé : $extension");

            return;
        }

        $fichier->move($this->dossierEtiquettes, $nom);

        $etiquette = (new Etiquette())
            ->setNomOriginal($nom)
            ->setChemin($nom)
            ->setTypeMime($fichier->getClientMimeType())
            ->setDeposePar($this->getUser());
        $em->persist($etiquette);
        $em->flush();
    }
}
