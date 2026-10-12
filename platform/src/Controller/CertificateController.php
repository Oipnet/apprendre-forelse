<?php

namespace App\Controller;

use App\Certificate\CertificateCode;
use App\Certificate\CertificateIssuer;
use App\Certificate\LinkedinCertification;
use App\Content\TrackVisibility;
use App\Entity\Certificate;
use App\Entity\User;
use App\Export\CertificatePdf;
use App\Export\PdfResponse;
use App\Form\CertificateClaimType;
use App\Repository\CertificateRepository;
use App\Seo\PageSeo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Le certificat de réussite d'un parcours : le demander une fois le parcours terminé, le montrer à qui veut le
 * vérifier (page publique, non indexée), le télécharger en PDF.
 */
final class CertificateController extends AbstractController
{
    use TargetPathTrait;

    public function __construct(private readonly CertificateRepository $certificates)
    {
    }

    /**
     * Confirmer le nom à imprimer, puis recevoir le certificat. Le chemin ressemble à celui d'un exercice : priorité
     * au certificat.
     */
    #[Route('/parcours/{trackId}/certificat', name: 'app_certificate_claim', methods: ['GET', 'POST'], priority: 1)]
    public function claim(string $trackId, Request $request, TrackVisibility $visibility, CertificateIssuer $issuer): Response
    {
        $track = $visibility->find($trackId) ?? throw $this->createNotFoundException();
        $user = $this->getUser();
        if (!$user instanceof User) {
            $this->saveTargetPath($request->getSession(), 'main', $request->getRequestUri());
            $this->addFlash('info', 'Connectez-vous pour obtenir votre certificat.');

            return $this->redirectToRoute('app_login');
        }
        if ($existing = $this->certificates->findValid($user, $track->id)) {
            return $this->redirectToRoute('app_certificate', ['code' => $existing->getCode()]);
        }
        if (!$issuer->isEligible($user, $track)) {
            $this->addFlash('info', 'Le certificat se délivre une fois tous les exercices du parcours réussis.');

            return $this->redirectToRoute('app_track', ['trackId' => $track->id]);
        }

        $form = $this->createForm(CertificateClaimType::class, ['holderName' => $user->getDisplayName()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $certificate = $issuer->issue($user, $track, $form->get('holderName')->getData());
            $this->addFlash('success', 'Votre certificat est prêt. Bravo !');

            return $this->redirectToRoute('app_certificate', ['code' => $certificate->getCode()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('certificate/claim.html.twig', [
            'track' => $track,
            'form' => $form,
            'previous' => $this->certificates->findLatest($user, $track->id),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** La page de vérification : publique, pour un recruteur ou une école. Hors de l'index (SearchIndexing). */
    #[Route('/certificat/{code}', name: 'app_certificate', requirements: ['code' => CertificateCode::PATTERN], methods: ['GET'])]
    public function show(string $code, PageSeo $seo, LinkedinCertification $linkedin): Response
    {
        $certificate = $this->find($code);
        if ($certificate->getCode() !== $code) {
            return $this->redirectToRoute('app_certificate', ['code' => $certificate->getCode()], Response::HTTP_MOVED_PERMANENTLY);
        }
        $url = $this->generateUrl('app_certificate', ['code' => $certificate->getCode()], UrlGeneratorInterface::ABSOLUTE_URL);
        $seo->setTitle(sprintf('Certificat « %s » de %s', $certificate->getTrackTitle(), $certificate->getHolderName()))
            ->setDescription(sprintf('%s a terminé le parcours « %s » : %d exercices réussis.', $certificate->getHolderName(), $certificate->getTrackTitle(), $certificate->getExerciseCount()))
            ->setCanonical($url);
        $user = $this->getUser();
        $holder = $user instanceof User && $user->getId() === $certificate->getUser()->getId();

        return $this->render('certificate/show.html.twig', [
            'certificate' => $certificate,
            'holder' => $holder,
            'linkedinUrl' => $holder && !$certificate->isRevoked() ? $linkedin->addToProfileUrl($certificate) : null,
        ]);
    }

    #[Route('/certificat/{code}/certificat.pdf', name: 'app_certificate_pdf', requirements: ['code' => CertificateCode::PATTERN], methods: ['GET'])]
    public function pdf(string $code, CertificatePdf $pdf): Response
    {
        $certificate = $this->find($code);
        if ($certificate->isRevoked()) {
            return $this->redirectToRoute('app_certificate', ['code' => $certificate->getCode()]);
        }

        return new PdfResponse($pdf->render($certificate), CertificatePdf::filename($certificate));
    }

    private function find(string $code): Certificate
    {
        return $this->certificates->findByCode($code) ?? throw $this->createNotFoundException('Aucun certificat ne porte ce code.');
    }
}
