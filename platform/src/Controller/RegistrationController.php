<?php

namespace App\Controller;

use App\Account\RegistrationThrottle;
use App\Account\UserRegistration;
use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Security\SafeRedirect;
use App\Seo\PageSeo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

final class RegistrationController extends AbstractController
{
    use TargetPathTrait;

    public function __construct(
        /** Bêta fermée : un code de cohorte est exigé (voir REGISTRATION_INVITE_ONLY dans .env). */
        #[Autowire(env: 'bool:REGISTRATION_INVITE_ONLY')]
        private readonly bool $inviteOnly,
        private readonly RegistrationThrottle $throttle,
        private readonly UserRegistration $registration,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(['fr' => '/inscription', 'en' => '/en/signup'], name: 'app_register')]
    public function register(Request $request, Security $security, PageSeo $seo): Response
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

        // Emails déjà pris et codes inconnus, limités par adresse IP (voir RegistrationThrottle).
        $ip = $request->getClientIp();
        $withCode = $form->isSubmitted() && $form->has('invitationCode') && '' !== trim((string) $form->get('invitationCode')->getData());
        if ($form->isSubmitted() && $this->throttle->isBlocked($ip)) {
            $form->addError(new FormError($this->translator->trans('erreur.inscriptions_refusees', [], 'compte')));
        } elseif ($withCode && $this->throttle->tooManyCodes($ip)) {
            $form->get('invitationCode')->addError(new FormError($this->translator->trans('erreur.trop_de_codes', [], 'compte')));
        } elseif ($withCode && !$form->get('invitationCode')->isValid()) {
            $this->throttle->recordUnknownCode($ip);
        } elseif ($form->isSubmitted() && !$form->isValid() && self::emailTaken($form)) {
            $this->throttle->recordDuplicate($ip);
            $this->registration->notifyHolder($user);
        } elseif ($form->isSubmitted() && $form->isValid()) {
            $result = $this->registration->register(
                $user,
                $form->get('plainPassword')->getData(),
                $form->has('invitationCode') ? (string) $form->get('invitationCode')->getData() : null,
            );

            // Retour là où l'apprenant voulait aller (ex. l'exercice suivant), sinon l'accueil.
            // Lu avant login() : le gestionnaire de succès du form_login consomme le chemin cible.
            $target = $this->getTargetPath($request->getSession(), 'main') ?? $request->query->get('suite');

            $security->login($user, 'form_login', 'main');
            $this->addFlash('success', t(
                $result->confirmationSent ? 'flash.bienvenue_confirmation' : 'flash.bienvenue',
                ['pseudo' => $user->getDisplayName(), 'email' => $user->getEmail()],
                'compte',
            ));

            // Mesure d'audience : l'inscription redirige, la page suivante envoie l'événement (voir _head.html.twig).
            $this->addFlash('mesure', 'compte-cree');

            // Chemin local uniquement : pas de redirection ouverte.
            return $this->redirect(SafeRedirect::localPath(\is_string($target) ? $target : null) ?? $this->generateUrl('app_home'));
        }

        $seo->setDescription($this->translator->trans('compte.inscription', [], 'seo'));

        return $this->render('security/register.html.twig', ['form' => $form, 'inviteOnly' => $this->inviteOnly]);
    }

    /** @param FormInterface<User> $form */
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
