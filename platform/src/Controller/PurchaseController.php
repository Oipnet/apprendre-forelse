<?php

namespace App\Controller;

use App\Content\TrackVisibility;
use App\Controller\LegalController;
use App\Entity\Purchase;
use App\Entity\User;
use App\Form\PurchaseConfirmationType;
use App\Payment\PaymentException;
use App\Payment\PaymentGateway;
use App\Payment\TrackOfferFactory;
use App\Payment\WithdrawalWaiver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Achat individuel d'un parcours : confirmation (prix, accès à vie, renonciation à la rétractation), puis Stripe
 * Checkout. L'accès n'est jamais ouvert ici : seulement par le webhook, une fois le paiement confirmé.
 */
final class PurchaseController extends AbstractController
{
    use TargetPathTrait;

    public function __construct(
        private readonly TrackVisibility $visibility,
        private readonly TrackOfferFactory $offers,
    ) {
    }

    /** Le chemin ressemble à celui d'un exercice (/parcours/{trackId}/{exerciseId}) : priorité à l'achat. */
    #[Route('/parcours/{trackId}/acheter', name: 'app_purchase', methods: ['GET', 'POST'], priority: 1)]
    public function checkout(string $trackId, Request $request, PaymentGateway $gateway, EntityManagerInterface $entityManager, ClockInterface $clock): Response
    {
        $track = $this->visibility->find($trackId) ?? throw $this->createNotFoundException();
        $user = $this->getUser();
        if (!$user instanceof User) {
            $this->saveTargetPath($request->getSession(), 'main', $request->getRequestUri());
            $this->addFlash('info', 'Connectez-vous (ou créez votre compte) pour acheter le parcours : l\'accès est rattaché à votre compte.');

            return $this->redirectToRoute('app_login');
        }

        $offer = $this->offers->create($track, $user);
        if (!$offer->isPurchasable()) {
            $this->addFlash('info', match (true) {
                $offer->quote->isFree() => 'Ce parcours est gratuit : il n\'y a rien à acheter.',
                $offer->isComingSoon() => 'L\'achat du parcours sera bientôt disponible.',
                default => 'Vous avez déjà accès à tout ce parcours.',
            });

            return $this->redirectToRoute('app_track', ['trackId' => $track->id]);
        }

        $form = $this->createForm(PurchaseConfirmationType::class, options: ['terms_url' => $this->generateUrl('app_terms')]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // Le prix est figé maintenant : celui que l'apprenant vient de voir, et que Stripe facturera.
            $purchase = new Purchase($user, $track->id, $offer->quote->price, $offer->quote->kind, WithdrawalWaiver::TEXT, $clock->now(), $offer->quote->cohort, LegalController::TERMS_VERSION);
            $entityManager->persist($purchase);
            $entityManager->flush();

            try {
                $session = $gateway->createCheckoutSession(
                    $purchase,
                    sprintf('Parcours « %s » (accès à vie)', $track->title),
                    $this->generateUrl('app_purchase_thanks', ['id' => $purchase->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                    $this->generateUrl('app_track', ['trackId' => $track->id], UrlGeneratorInterface::ABSOLUTE_URL),
                );
            } catch (PaymentException $e) {
                $this->addFlash('danger', $e->getMessage().' Rien n\'a été débité ; réessayez dans un instant.');

                return $this->redirectToRoute('app_purchase', ['trackId' => $track->id]);
            }
            $purchase->attachCheckoutSession($session->id);
            $entityManager->flush();

            return $this->redirect($session->url, Response::HTTP_SEE_OTHER);
        }

        return $this->render('purchase/checkout.html.twig', [
            'track' => $track,
            'offer' => $offer,
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Retour de Stripe. N'ouvre rien : affiche l'état de l'achat, et patiente tant que le webhook n'est pas passé.
     */
    #[Route('/achat/{id}/merci', name: 'app_purchase_thanks', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function thanks(#[MapEntity(id: 'id')] Purchase $purchase): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || $purchase->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        return $this->render('purchase/thanks.html.twig', [
            'purchase' => $purchase,
            'track' => $this->visibility->find($purchase->getTrackId()),
        ]);
    }
}
