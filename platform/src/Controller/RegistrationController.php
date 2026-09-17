<?php

namespace App\Controller;

use App\Account\EmailVerifier;
use App\Cohort\CohortAccessSync;
use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\CohortRepository;
use App\Service\RegistrationAlert;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class RegistrationController extends AbstractController
{
    use TargetPathTrait;

    public function __construct(
        /** Bêta fermée : un code de cohorte est exigé (voir REGISTRATION_INVITE_ONLY dans .env). */
        #[Autowire(env: 'bool:REGISTRATION_INVITE_ONLY')]
        private readonly bool $inviteOnly,
    ) {
    }

    #[Route('/inscription', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $entityManager, Security $security, CohortRepository $cohorts, RegistrationAlert $alert, CohortAccessSync $cohortAccess, EmailVerifier $verifier): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user, [
            'invite_only' => $this->inviteOnly,
            // Lien d'invitation (/inscription?code=…) : le code est pré-rempli.
            'invitation_code' => $request->query->getString('code') ?: null,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $code = $form->has('invitationCode') ? trim((string) $form->get('invitationCode')->getData()) : '';
            if ('' !== $code) {
                $user->setCohort($cohorts->findActiveByCode($code));
            }
            $entityManager->persist($user);
            $entityManager->flush();
            if (null !== $user->getCohort()) {
                // Cohorte financée par l'établissement : ses parcours s'ouvrent dès l'inscription.
                $cohortAccess->join($user, $user->getCohort());
            }
            $alert->notify($user);
            $confirmationSent = $verifier->send($user);

            // Retour là où l'apprenant voulait aller (ex. l'exercice suivant), sinon l'accueil.
            // Lu avant login() : le gestionnaire de succès du form_login consomme le chemin cible.
            $target = $this->getTargetPath($request->getSession(), 'main') ?? $request->query->get('suite');

            $security->login($user, 'form_login', 'main');
            $this->addFlash('success', sprintf(
                'Bienvenue, %s ! Votre compte est créé : votre progression est désormais sauvegardée.%s',
                $user->getDisplayName(),
                $confirmationSent ? sprintf(' Un email vient de partir à %s pour confirmer votre adresse.', $user->getEmail()) : '',
            ));

            // Chemin local uniquement (« //hote » ou « /\hote » mèneraient ailleurs : redirection ouverte).
            $isLocalPath = \is_string($target) && 1 === preg_match('#^/(?![/\\\\])#', $target);

            return $this->redirect($isLocalPath ? $target : $this->generateUrl('app_home'));
        }

        return $this->render('security/register.html.twig', ['form' => $form, 'inviteOnly' => $this->inviteOnly]);
    }
}
