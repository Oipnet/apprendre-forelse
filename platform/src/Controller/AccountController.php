<?php

namespace App\Controller;

use App\Account\AccountProgress;
use App\Account\EmailChangeNotice;
use App\Account\EmailVerifier;
use App\Content\ContentRepository;
use App\Entity\Purchase;
use App\Entity\User;
use App\Form\Account\DeleteAccountFormType;
use App\Form\Account\PasswordFormType;
use App\Form\Account\ProfileFormType;
use App\Payment\PaymentException;
use App\Payment\PaymentGateway;
use App\Repository\PurchaseRepository;
use App\Repository\TrackAccessRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le compte de l'apprenant : sa progression et d'où reprendre, ses accès, ses achats (reçus et factures),
 * son profil, son mot de passe, la confirmation de son adresse et la suppression du compte.
 */
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailVerifier $verifier,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.email_confirmation')]
        private readonly RateLimiterFactoryInterface $confirmationLimiter,
    ) {
    }

    #[Route('/compte', name: 'app_account', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(TrackAccessRepository $accesses, PurchaseRepository $purchases, ContentRepository $content, AccountProgress $progress): Response
    {
        return $this->renderPage($accesses, $purchases, $content, $progress);
    }

    #[Route('/compte/profil', name: 'app_account_profile', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function profile(Request $request, UserRepository $users, UserPasswordHasherInterface $hasher, TrackAccessRepository $accesses, PurchaseRepository $purchases, ContentRepository $content, AccountProgress $progress): Response
    {
        $user = $this->user();
        $form = $this->profileForm($user)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{displayName: string, email: string, currentPassword: string|null} $data */
            $data = $form->getData();
            $email = trim($data['email']);
            $emailChanged = 0 !== strcasecmp($email, (string) $user->getEmail());
            if ($emailChanged && !$hasher->isPasswordValid($user, (string) $data['currentPassword'])) {
                // Avant de dire si l'adresse est prise : sans le mot de passe, on n'apprend rien.
                $form->get('currentPassword')->addError(new FormError('Votre mot de passe actuel est demandé pour changer d\'adresse.'));
            } elseif ($emailChanged && null !== $users->findOneBy(['email' => $email])) {
                $form->get('email')->addError(new FormError('Un compte existe déjà avec cet email.'));
            } else {
                $user->setDisplayName(trim($data['displayName']));
                $this->entityManager->flush();
                if (!$emailChanged) {
                    $this->addFlash('success', 'Profil enregistré.');
                } elseif (!$this->canSendConfirmation($user)) {
                    $this->addFlash('error', 'Trop de demandes de confirmation : réessayez dans une heure.');
                } elseif ($this->verifier->send($user, $email)) {
                    $this->addFlash('success', sprintf('Un lien de confirmation vient de partir à %s. Votre adresse actuelle reste valable jusqu\'à ce que vous l\'ouvriez.', $email));
                } else {
                    $this->addFlash('error', 'L\'email de confirmation n\'a pas pu partir. Réessayez dans quelques minutes.');
                }

                return $this->redirectToRoute('app_account', ['_fragment' => 'profil'], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->renderPage($accesses, $purchases, $content, $progress, profile: $form);
    }

    #[Route('/compte/mot-de-passe', name: 'app_account_password', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function password(Request $request, UserPasswordHasherInterface $hasher, Security $security, TrackAccessRepository $accesses, PurchaseRepository $purchases, ContentRepository $content, AccountProgress $progress): Response
    {
        $user = $this->user();
        $form = $this->createForm(PasswordFormType::class, options: ['action' => $this->generateUrl('app_account_password')])->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $this->entityManager->flush();
            // Le mot de passe fait partie de la session : on reconnecte l'apprenant ; ses autres appareils sont déconnectés.
            $security->login($user, 'form_login', 'main');
            $this->addFlash('success', 'Mot de passe changé. Vos autres appareils devront se reconnecter.');

            return $this->redirectToRoute('app_account', ['_fragment' => 'mot-de-passe'], Response::HTTP_SEE_OTHER);
        }

        return $this->renderPage($accesses, $purchases, $content, $progress, password: $form);
    }

    #[Route('/compte/confirmation', name: 'app_account_resend_confirmation', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function resendConfirmation(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        $user = $this->user();
        if ($user->isEmailVerified()) {
            $this->addFlash('success', 'Votre adresse est déjà confirmée.');
        } elseif (!$this->canSendConfirmation($user)) {
            $this->addFlash('error', 'Trop de demandes de confirmation : réessayez dans une heure.');
        } elseif ($this->verifier->send($user)) {
            $this->addFlash('success', sprintf('Un nouveau lien de confirmation vient de partir à %s.', $user->getEmail()));
        } else {
            $this->addFlash('error', 'L\'email de confirmation n\'a pas pu partir. Réessayez dans quelques minutes.');
        }

        return $this->redirectToRoute('app_account', status: Response::HTTP_SEE_OTHER);
    }

    /** Le lien reçu par email. Il fonctionne sans être connecté : on l'ouvre souvent depuis un autre appareil. */
    #[Route('/compte/confirmer-email', name: 'app_account_confirm_email', methods: ['GET'])]
    public function confirmEmail(Request $request, UserRepository $users, Security $security, EmailChangeNotice $notice): Response
    {
        $user = $users->find($request->query->getInt('id'));
        $email = $request->query->getString('email');
        $current = $this->getUser();

        if (null === $user || !$this->verifier->isSigned($request)) {
            return $this->afterConfirmation('error', 'Ce lien de confirmation n\'est pas valide ou a expiré. Vous pouvez en demander un nouveau depuis votre compte.');
        }
        if ($user->isEmailVerified() && $user->getEmail() === $email) {
            return $this->afterConfirmation('success', 'Votre adresse est déjà confirmée.');
        }
        if (!$this->verifier->matches($user, $request->query->getString('check'))) {
            return $this->afterConfirmation('error', 'Ce lien de confirmation a déjà servi ou ne correspond plus à votre compte. Vous pouvez en demander un nouveau depuis votre compte.');
        }
        $other = $users->findOneBy(['email' => $email]);
        if (null !== $other && $other->getId() !== $user->getId()) {
            return $this->afterConfirmation('error', 'Cette adresse est déjà utilisée par un autre compte.');
        }

        $previous = (string) $user->getEmail();
        $changed = $previous !== $email;
        $user->confirmEmail($email, $this->clock->now());
        $this->entityManager->flush();
        if ($changed) {
            // L'ancienne adresse apprend le changement : si ce n'était pas son titulaire, il sait qu'il doit réagir.
            $notice->send($user, $previous);
        }
        // L'adresse identifie l'apprenant dans la session : sans reconnexion, il serait déconnecté à la page suivante.
        if ($changed && $current instanceof User && $current->getId() === $user->getId()) {
            $security->login($user, 'form_login', 'main');
        }

        return $this->afterConfirmation('success', $changed ? sprintf('Adresse confirmée : votre compte utilise désormais %s.', $email) : 'Adresse confirmée, merci.');
    }

    #[Route('/compte/achats/{id}/facture', name: 'app_account_invoice', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function invoice(Purchase $purchase, PaymentGateway $payments): Response
    {
        if ($purchase->getUser()?->getId() !== $this->user()->getId() || null === $purchase->getStripeInvoiceId()) {
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
    public function delete(Request $request, Security $security, TrackAccessRepository $accesses, PurchaseRepository $purchases, ContentRepository $content, AccountProgress $progress): Response
    {
        $user = $this->user();
        $form = $this->createForm(DeleteAccountFormType::class, options: ['action' => $this->generateUrl('app_account_delete')])->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderPage($accesses, $purchases, $content, $progress, delete: $form);
        }

        // Progression, avis et accès partent avec le compte ; les achats restent, sans lien vers lui (pièces comptables).
        $this->entityManager->remove($user);
        $this->entityManager->flush();
        $security->logout(false);
        $this->addFlash('success', 'Votre compte a été supprimé, avec votre progression. Merci d\'être passé.');

        return $this->redirectToRoute('app_home', status: Response::HTTP_SEE_OTHER);
    }

    private function afterConfirmation(string $type, string $message): Response
    {
        $this->addFlash($type, $message);

        return $this->redirectToRoute($this->getUser() ? 'app_account' : 'app_login');
    }

    /** Un lien par email coûte un envoi : quelques-uns par heure et par compte. */
    private function canSendConfirmation(User $user): bool
    {
        return $this->confirmationLimiter->create('user-'.$user->getId())->consume()->isAccepted();
    }

    /** @return FormInterface<array{displayName: string, email: string}> */
    private function profileForm(User $user): FormInterface
    {
        return $this->createForm(ProfileFormType::class, ['displayName' => $user->getDisplayName(), 'email' => $user->getEmail()], ['action' => $this->generateUrl('app_account_profile')]);
    }

    /**
     * La page entière ; un formulaire soumis avec des erreurs la réaffiche en 422, ses erreurs à leur place.
     *
     * @param FormInterface<mixed>|null $profile
     * @param FormInterface<mixed>|null $password
     * @param FormInterface<mixed>|null $delete
     */
    private function renderPage(TrackAccessRepository $accesses, PurchaseRepository $purchases, ContentRepository $content, AccountProgress $progress, ?FormInterface $profile = null, ?FormInterface $password = null, ?FormInterface $delete = null): Response
    {
        $user = $this->user();
        $failed = null !== $profile || null !== $password || null !== $delete;

        return $this->render('account/index.html.twig', [
            'progress' => $progress->tracks($user),
            'practiceCompleted' => $progress->practiceCompleted($user),
            'completedExercises' => $progress->completedExercises($user),
            'activeAccesses' => $progress->activeAccesses($user),
            'accesses' => $accesses->findByUser($user),
            'purchases' => $purchases->findByUser($user),
            'tracks' => $content->tracks(),
            'now' => $this->clock->now(),
            'profileForm' => $profile ?? $this->profileForm($user),
            'passwordForm' => $password ?? $this->createForm(PasswordFormType::class, options: ['action' => $this->generateUrl('app_account_password')]),
            'deleteForm' => $delete ?? $this->createForm(DeleteAccountFormType::class, options: ['action' => $this->generateUrl('app_account_delete')]),
            'deleteOpen' => null !== $delete,
        ], new Response(status: $failed ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
