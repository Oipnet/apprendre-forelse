<?php

namespace App\Controller;

use App\Account\AccountManagement;
use App\Account\Github\RecentSignIn;
use App\Account\AccountPage;
use App\Account\EmailChange;
use App\Account\EmailChangeOutcome;
use App\Account\EmailConfirmationOutcome;
use App\Entity\Purchase;
use App\Entity\User;
use App\Form\Account\DeleteAccountFormType;
use App\Form\Account\PasswordFormType;
use App\Form\Account\ProfileFormType;
use App\Payment\PaymentException;
use App\Payment\PaymentGateway;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le compte de l'apprenant : sa progression et d'où reprendre, ses accès, ses achats (reçus et factures),
 * son profil, son mot de passe, la confirmation de son adresse et la suppression du compte.
 */
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly AccountManagement $account,
        private readonly EmailChange $emailChange,
        private readonly AccountPage $page,
        private readonly RecentSignIn $recent,
    ) {
    }

    #[Route('/compte', name: 'app_account', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        return $this->renderPage();
    }

    #[Route('/compte/profil', name: 'app_account_profile', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function profile(Request $request): Response
    {
        $user = $this->user();
        $form = $this->profileForm($user)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{displayName: string, email: string, currentPassword: string|null} $data */
            $data = $form->getData();
            $email = trim($data['email']);
            $outcome = $this->emailChange->request($user, $data['displayName'], $email, $data['currentPassword'] ?? null, $this->recent->isRecent($user));
            match ($outcome) {
                EmailChangeOutcome::PasswordRequired => $user->hasPassword()
                    ? $form->get('currentPassword')->addError(new FormError('Votre mot de passe actuel est demandé pour changer d\'adresse.'))
                    : $form->addError(new FormError('Confirmez d\'abord votre identité avec GitHub pour changer d\'adresse.')),
                EmailChangeOutcome::EmailTaken => $form->get('email')->addError(new FormError('Un compte existe déjà avec cet email.')),
                EmailChangeOutcome::Saved => $this->addFlash('success', 'Profil enregistré.'),
                EmailChangeOutcome::Sent => $this->addFlash('success', sprintf('Un lien de confirmation vient de partir à %s. Votre adresse actuelle reste valable jusqu\'à ce que vous l\'ouvriez.', $email)),
                default => $this->confirmationNotSent($outcome),
            };
            if (!\in_array($outcome, [EmailChangeOutcome::PasswordRequired, EmailChangeOutcome::EmailTaken], true)) {
                return $this->redirectToRoute('app_account', ['_fragment' => 'profil'], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->renderPage(profile: $form);
    }

    #[Route('/compte/mot-de-passe', name: 'app_account_password', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function password(Request $request, Security $security): Response
    {
        $user = $this->user();
        $form = $this->createForm(PasswordFormType::class, options: ['action' => $this->generateUrl('app_account_password')])->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->account->changePassword($user, $form->get('plainPassword')->getData());
            // Le mot de passe fait partie de la session : on reconnecte l'apprenant ; ses autres appareils sont déconnectés.
            $security->login($user, 'form_login', 'main');
            $this->addFlash('success', 'Mot de passe changé. Vos autres appareils devront se reconnecter.');

            return $this->redirectToRoute('app_account', ['_fragment' => 'mot-de-passe'], Response::HTTP_SEE_OTHER);
        }

        return $this->renderPage(password: $form);
    }

    #[Route('/compte/confirmation', name: 'app_account_resend_confirmation', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function resendConfirmation(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        $user = $this->user();
        match ($outcome = $this->emailChange->resend($user)) {
            EmailChangeOutcome::AlreadyConfirmed => $this->addFlash('success', 'Votre adresse est déjà confirmée.'),
            EmailChangeOutcome::Sent => $this->addFlash('success', sprintf('Un nouveau lien de confirmation vient de partir à %s.', $user->getEmail())),
            default => $this->confirmationNotSent($outcome),
        };

        return $this->redirectToRoute('app_account', status: Response::HTTP_SEE_OTHER);
    }

    /** Le lien reçu par email. Il fonctionne sans être connecté : on l'ouvre souvent depuis un autre appareil. */
    #[Route('/compte/confirmer-email', name: 'app_account_confirm_email', methods: ['GET'])]
    public function confirmEmail(Request $request, Security $security): Response
    {
        $email = $request->query->getString('email');
        // Lu avant la confirmation : la session, chargée après le changement d'adresse, ne reconnaîtrait plus le compte.
        $current = $this->getUser();

        return match ($this->emailChange->confirm($request)) {
            EmailConfirmationOutcome::Invalid => $this->afterConfirmation('error', 'Ce lien de confirmation n\'est pas valide ou a expiré. Vous pouvez en demander un nouveau depuis votre compte.'),
            EmailConfirmationOutcome::AlreadyConfirmed => $this->afterConfirmation('success', 'Votre adresse est déjà confirmée.'),
            EmailConfirmationOutcome::Outdated => $this->afterConfirmation('error', 'Ce lien de confirmation a déjà servi ou ne correspond plus à votre compte. Vous pouvez en demander un nouveau depuis votre compte.'),
            EmailConfirmationOutcome::EmailTaken => $this->afterConfirmation('error', 'Cette adresse est déjà utilisée par un autre compte.'),
            EmailConfirmationOutcome::Confirmed => $this->afterConfirmation('success', 'Adresse confirmée, merci.'),
            EmailConfirmationOutcome::Changed => $this->afterEmailChange($request, $security, $current, $email),
        };
    }

    #[Route('/compte/achats/{id}/facture', name: 'app_account_invoice', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function invoice(Purchase $purchase, PaymentGateway $payments): Response
    {
        if (!$purchase->isOwnedBy($this->user()) || null === $purchase->getStripeInvoiceId()) {
            throw $this->createNotFoundException();
        }
        try {
            $url = $payments->invoicePdfUrl($purchase->getStripeInvoiceId());
        } catch (PaymentException) {
            $url = null;
        }
        if (null === $url) {
            $this->addFlash('error', 'La facture n\'est pas disponible pour le moment. Réessayez plus tard, ou écrivez-nous.');

            return $this->redirectToRoute('app_account', ['_fragment' => 'achats']);
        }

        return new RedirectResponse($url);
    }

    #[Route('/compte/supprimer', name: 'app_account_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(Request $request, Security $security): Response
    {
        $user = $this->user();
        $form = $this->deleteForm($user)->handleRequest($request);
        if ($form->isSubmitted() && !$user->hasPassword() && !$this->recent->isRecent($user)) {
            $form->addError(new FormError('Confirmez d\'abord votre identité avec GitHub.'));
        }
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderPage(delete: $form);
        }

        $this->account->delete($user);
        $security->logout(false);
        $this->addFlash('success', 'Votre compte a été supprimé, avec votre progression. Merci d\'être passé.');

        return $this->redirectToRoute('app_home', status: Response::HTTP_SEE_OTHER);
    }

    private function afterConfirmation(string $type, string $message): Response
    {
        $this->addFlash($type, $message);

        return $this->redirectToRoute($this->getUser() ? 'app_account' : 'app_login');
    }

    /** L'adresse identifie l'apprenant dans la session : sans reconnexion, il serait déconnecté à la page suivante. */
    private function afterEmailChange(Request $request, Security $security, ?UserInterface $current, string $email): Response
    {
        if ($current instanceof User && $current->getId() === $request->query->getInt('id')) {
            $security->login($current, 'form_login', 'main');
        }

        return $this->afterConfirmation('success', sprintf('Adresse confirmée : votre compte utilise désormais %s.', $email));
    }

    private function confirmationNotSent(EmailChangeOutcome $outcome): void
    {
        $this->addFlash('error', EmailChangeOutcome::Throttled === $outcome
            ? 'Trop de demandes de confirmation : réessayez dans une heure.'
            : 'L\'email de confirmation n\'a pas pu partir. Réessayez dans quelques minutes.');
    }

    /** @return FormInterface<array{displayName: string|null, email: string|null, currentPassword?: string|null}> */
    private function profileForm(User $user): FormInterface
    {
        return $this->createForm(ProfileFormType::class, ['displayName' => $user->getDisplayName(), 'email' => $user->getEmail()], ['action' => $this->generateUrl('app_account_profile'), 'with_password' => $user->hasPassword()]);
    }

    /** @return FormInterface<array{password: string|null}|null> */
    private function deleteForm(User $user): FormInterface
    {
        return $this->createForm(DeleteAccountFormType::class, options: ['action' => $this->generateUrl('app_account_delete'), 'with_password' => $user->hasPassword()]);
    }

    /**
     * La page entière ; un formulaire soumis avec des erreurs la réaffiche en 422, ses erreurs à leur place.
     *
     * @param FormInterface<mixed>|null $profile
     * @param FormInterface<mixed>|null $password
     * @param FormInterface<mixed>|null $delete
     */
    private function renderPage(?FormInterface $profile = null, ?FormInterface $password = null, ?FormInterface $delete = null): Response
    {
        $user = $this->user();
        $failed = null !== $profile || null !== $password || null !== $delete;

        return $this->render('account/index.html.twig', [
            ...$this->page->of($user),
            'profileForm' => $profile ?? $this->profileForm($user),
            'passwordForm' => $password ?? $this->createForm(PasswordFormType::class, options: ['action' => $this->generateUrl('app_account_password')]),
            'deleteForm' => $delete ?? $this->deleteForm($user),
            'deleteOpen' => null !== $delete,
            // Compte sans mot de passe : GitHub confirme l'identité (changer d'adresse, supprimer le compte).
            'recentSignIn' => $this->recent->isRecent($user),
        ], new Response(status: $failed ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
