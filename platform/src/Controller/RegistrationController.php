<?php

namespace App\Controller;

use App\Account\EmailVerifier;
use App\Account\RegistrationAttemptNotice;
use App\Cohort\CohortAccessSync;
use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\CohortRepository;
use App\Repository\UserRepository;
use App\Service\RegistrationAlert;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Component\Validator\ConstraintViolation;

final class RegistrationController extends AbstractController
{
    use TargetPathTrait;

    public function __construct(
        /** Bêta fermée : un code de cohorte est exigé (voir REGISTRATION_INVITE_ONLY dans .env). */
        #[Autowire(env: 'bool:REGISTRATION_INVITE_ONLY')]
        private readonly bool $inviteOnly,
        /** Inscriptions refusées pour email déjà pris, par adresse IP (config/packages/rate_limiter.yaml). */
        #[Autowire(service: 'limiter.registration_duplicate')]
        private readonly RateLimiterFactoryInterface $duplicates,
        /** Codes d'invitation inconnus, par adresse IP : un code ouvre des comptes, parfois des parcours payants. */
        #[Autowire(service: 'limiter.invitation_code')]
        private readonly RateLimiterFactoryInterface $invitationCodes,
    ) {
    }

    #[Route('/inscription', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $entityManager, Security $security, CohortRepository $cohorts, RegistrationAlert $alert, CohortAccessSync $cohortAccess, EmailVerifier $verifier, UserRepository $users, RegistrationAttemptNotice $notice): Response
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

        // Un email déjà pris se voit forcément : un nouveau compte, lui, est connecté aussitôt. Ce qu'on peut faire,
        // c'est ralentir qui teste une liste d'adresses : passé un certain nombre de refus, plus aucune inscription
        // n'aboutit depuis cette adresse IP, que l'email soit pris ou non.
        $duplicates = $this->duplicates->create($request->getClientIp() ?? 'inconnu');
        $codes = $this->invitationCodes->create($request->getClientIp() ?? 'inconnu');
        $withCode = $form->isSubmitted() && $form->has('invitationCode') && '' !== trim((string) $form->get('invitationCode')->getData());
        if ($form->isSubmitted() && 0 === $duplicates->consume(0)->getRemainingTokens()) {
            $form->addError(new FormError('Beaucoup d\'inscriptions refusées depuis votre connexion : réessayez dans une heure.'));
        } elseif ($withCode && 0 === $codes->consume(0)->getRemainingTokens()) {
            $form->get('invitationCode')->addError(new FormError('Trop de codes d\'invitation essayés depuis votre connexion : réessayez dans une heure, ou demandez le lien d\'invitation à votre formateur.'));
        } elseif ($withCode && !$form->get('invitationCode')->isValid()) {
            $codes->consume();
        } elseif ($form->isSubmitted() && !$form->isValid() && self::emailTaken($form)) {
            $duplicates->consume();
            $holder = $users->findOneBy(['email' => $user->getEmail()]);
            if ($holder instanceof User) {
                $notice->send($holder);
            }
        } elseif ($form->isSubmitted() && $form->isValid()) {
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

            // Chemin local uniquement (« //hote » ou « /\hote » mèneraient ailleurs : redirection ouverte). Ni blanc ni
            // caractère de contrôle : le navigateur retire une tabulation, et « /<tab>/hote » redeviendrait « //hote ».
            $isLocalPath = \is_string($target) && 1 === preg_match('#^/(?![/\\\\])[^\s\x00-\x1f\x7f]*$#', $target);

            return $this->redirect($isLocalPath ? $target : $this->generateUrl('app_home'));
        }

        return $this->render('security/register.html.twig', ['form' => $form, 'inviteOnly' => $this->inviteOnly]);
    }

    private static function emailTaken(FormInterface $form): bool
    {
        foreach ($form->getErrors(true) as $error) {
            $cause = $error->getCause();
            if ($cause instanceof ConstraintViolation && UniqueEntity::NOT_UNIQUE_ERROR === $cause->getCode()) {
                return true;
            }
        }

        return false;
    }
}
