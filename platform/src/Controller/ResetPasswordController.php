<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
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
 * La page « email envoyé » est la même que l'adresse soit connue ou non : on ne révèle pas
 * quels emails ont un compte.
 */
#[Route('/mot-de-passe-oublie')]
final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
        /** Expéditeur des emails (« Nom <adresse> »), voir MAILER_FROM dans .env. */
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $mailerFrom,
    ) {
    }

    #[Route('', name: 'app_forgot_password_request')]
    public function request(Request $request, UserRepository $users, MailerInterface $mailer): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $users->findOneBy(['email' => $form->get('email')->getData()]);
            if ($user instanceof User) {
                $this->sendResetEmail($user, $mailer);
            }

            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('security/reset_password/request.html.twig', ['form' => $form]);
    }

    /** Page affichée après la demande, que l'email existe ou non. */
    #[Route('/email-envoye', name: 'app_check_email')]
    public function checkEmail(): Response
    {
        // Sans demande en session (adresse inconnue, ou page ouverte directement) : un jeton factice,
        // pour afficher la même durée de validité dans tous les cas.
        $resetToken = $this->getTokenObjectFromSession() ?? $this->resetPasswordHelper->generateFakeResetToken();

        return $this->render('security/reset_password/check_email.html.twig', ['resetToken' => $resetToken]);
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
            $this->addFlash('success', 'Mot de passe modifié. Bon retour à la taverne !');

            return $this->redirectToRoute('app_home');
        }

        return $this->render('security/reset_password/reset.html.twig', ['form' => $form]);
    }

    private function sendResetEmail(User $user, MailerInterface $mailer): void
    {
        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            // Demande trop rapprochée de la précédente : on n'envoie rien, sans le dire (sinon on
            // révélerait que l'adresse a un compte).
            return;
        }

        $mailer->send((new TemplatedEmail())
            ->from(Address::create($this->mailerFrom))
            ->to(new Address((string) $user->getEmail(), (string) $user->getDisplayName()))
            ->subject('Votre nouveau mot de passe Forelse')
            ->htmlTemplate('emails/reset_password.html.twig')
            ->textTemplate('emails/reset_password.txt.twig')
            ->context(['resetToken' => $resetToken, 'user' => $user]));

        // Pour la page « email envoyé » : la durée de validité, sans le jeton lui-même.
        $this->setTokenObjectInSession($resetToken);
    }
}
