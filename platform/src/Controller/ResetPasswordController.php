<?php

namespace App\Controller;

use App\Account\PasswordResetRequested;
use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ExpiredResetPasswordTokenException;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Mot de passe oublié : l'apprenant saisit son email, reçoit un lien à usage unique (valable une
 * heure, voir config/packages/reset_password.yaml) et choisit un nouveau mot de passe.
 *
 * La page « email envoyé » est la même que l'adresse soit connue ou non, et la requête fait le même travail
 * dans les deux cas : déposer la demande dans la file d'attente (PasswordResetRequested). C'est le worker qui
 * cherche le compte et envoie le lien ; le temps de réponse ne dit pas quels emails ont un compte.
 */
#[Route('/mot-de-passe-oublie')]
final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_forgot_password_request')]
    public function request(Request $request, MessageBusInterface $bus): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $bus->dispatch(new PasswordResetRequested((string) $form->get('email')->getData()));

            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('security/reset_password/request.html.twig', ['form' => $form]);
    }

    /** Page affichée après la demande, que l'email existe ou non. */
    #[Route('/email-envoye', name: 'app_check_email')]
    public function checkEmail(): Response
    {
        // Le lien part plus tard, s'il part : un jeton factice donne la durée de validité, la même pour tous.
        return $this->render('security/reset_password/check_email.html.twig', ['resetToken' => $this->resetPasswordHelper->generateFakeResetToken()]);
    }

    /** Lien reçu par email : vérifie le jeton, puis propose de choisir le nouveau mot de passe. */
    #[Route('/nouveau/{token}', name: 'app_reset_password')]
    public function reset(Request $request, UserPasswordHasherInterface $hasher, Security $security, ?string $token = null): Response
    {
        if (null !== $token) {
            // Le jeton passe en session et disparaît de l'URL : il ne doit pas fuiter via l'historique
            // du navigateur ou un en-tête Referer.
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();
        if (null === $token) {
            throw $this->createNotFoundException('Aucun jeton de réinitialisation.');
        }

        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
            \assert($user instanceof User);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->cleanSessionAfterReset();
            $this->addFlash('error', $e instanceof ExpiredResetPasswordTokenException
                ? 'Ce lien a expiré. Refaites une demande pour en recevoir un nouveau.'
                : 'Ce lien de réinitialisation n\'est pas valide, ou a déjà servi. Refaites une demande.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Le lien ne sert qu'une fois.
            $this->resetPasswordHelper->removeResetRequest($token);
            $user->setPassword($hasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $this->entityManager->flush();
            $this->cleanSessionAfterReset();

            // L'apprenant vient de prouver qu'il contrôle l'adresse du compte : inutile de lui redemander
            // le mot de passe qu'il vient de choisir.
            $security->login($user, 'form_login', 'main');
            $this->addFlash('success', 'Mot de passe modifié. Bon retour !');

            return $this->redirectToRoute('app_home');
        }

        return $this->render('security/reset_password/reset.html.twig', ['form' => $form]);
    }
}
