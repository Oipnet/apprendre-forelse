<?php

namespace App\Controller\Cohort;

use App\Cohort\CohortRuleViolation;
use App\Cohort\FreeClasses;
use App\Entity\User;
use App\Form\FreeClassType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Un formateur crée sa classe gratuite (FreeClasses) : un nom, des exercices de la Pratique, et il arrive dans son
 * espace de chef de cohorte avec le lien d'invitation. Un visiteur passe d'abord par la connexion ou l'inscription,
 * qui le ramènent ici. Offre française, comme la page des écoles et entreprises.
 */
final class FreeClassController extends AbstractController
{
    #[Route('/ecoles-et-entreprises/classe-gratuite', name: 'app_free_class', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function __invoke(Request $request, FreeClasses $freeClasses, Security $security): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $remaining = $freeClasses->remaining($user);
        $form = $this->createForm(FreeClassType::class, null, ['practices' => $freeClasses->choices()]);
        $form->handleRequest($request);

        if ($remaining && $form->isSubmitted() && $form->isValid()) {
            try {
                $cohort = $freeClasses->create($user, $form->get('name')->getData(), $form->get('practiceIds')->getData());
            } catch (CohortRuleViolation $e) {
                $form->addError(new FormError($e->getMessage()));

                return $this->render('contact/free_class.html.twig', ['form' => $form, 'remaining' => $remaining, 'classes' => $freeClasses->of($user)], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }
            // Le jeton de session porte encore les anciens rôles : sans ce renouvellement, l'espace /cohorte resterait fermé.
            $security->login($user, 'form_login', 'main');
            $this->addFlash('success', sprintf('Votre classe « %s » est prête : envoyez le lien d\'invitation à vos élèves.', $cohort->getName()));

            return $this->redirectToRoute('chef_cohort', ['id' => $cohort->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('contact/free_class.html.twig', [
            'form' => $form,
            'remaining' => $remaining,
            'classes' => $freeClasses->of($user),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
