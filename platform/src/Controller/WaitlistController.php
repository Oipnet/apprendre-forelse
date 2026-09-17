<?php

namespace App\Controller;

use App\Entity\WaitlistEntry;
use App\Repository\WaitlistEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Liste d'attente de la page d'accueil (bêta fermée) : une adresse, rien d'autre.
 * Le résultat est rendu par la page d'accueil elle-même (?liste=ok|invalide), pas par un flash
 * qui s'afficherait en haut de page, loin du formulaire.
 */
final class WaitlistController extends AbstractController
{
    #[Route('/liste-d-attente', name: 'app_waitlist', methods: ['POST'])]
    public function join(Request $request, WaitlistEntryRepository $entries, EntityManagerInterface $entityManager, ValidatorInterface $validator): Response
    {
        // 403 direct : l'exception « accès refusé » de la sécurité enverrait l'invité vers la page de connexion.
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }

        // Champ caché que seuls les robots remplissent : on fait comme si tout allait bien, sans rien enregistrer.
        if ('' !== $request->request->getString('site')) {
            return $this->backToForm('ok');
        }

        $email = trim($request->request->getString('email'));
        $violations = $validator->validate($email, [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 180)]);
        if (\count($violations) > 0) {
            return $this->backToForm('invalide');
        }

        if (!$entries->findOneByEmail($email)) {
            $entityManager->persist(new WaitlistEntry($email));
            $entityManager->flush();
        }

        return $this->backToForm('ok');
    }

    private function backToForm(string $state): Response
    {
        return $this->redirect($this->generateUrl('app_home', ['liste' => $state]).'#liste-attente', Response::HTTP_SEE_OTHER);
    }
}
