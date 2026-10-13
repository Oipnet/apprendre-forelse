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
use App\Share\CertificateShare;
use App\Share\ShareImage;
use App\Share\ShareImageRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

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
            $this->addFlash('info', t('flash.connexion', [], 'certificat'));

            return $this->redirectToRoute('app_login');
        }
        if ($existing = $this->certificates->findValid($user, $track->id)) {
            return $this->redirectToRoute('app_certificate', ['code' => $existing->getCode()]);
        }
        if (!$issuer->isComplete($user, $track)) {
            $this->addFlash('info', t('flash.incomplet', [], 'certificat'));

            return $this->redirectToRoute('app_track', ['trackId' => $track->id]);
        }
        $unconfirmed = $issuer->unconfirmed($user, $track);
        if ($unconfirmed['pending'] || $unconfirmed['failed']) {
            return $this->render('certificate/claim.html.twig', ['track' => $track, 'form' => null, 'unconfirmed' => $unconfirmed, 'previous' => null]);
        }

        $form = $this->createForm(CertificateClaimType::class, ['holderName' => $user->getDisplayName()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $certificate = $issuer->issue($user, $track, $form->get('holderName')->getData());
            $this->addFlash('success', t('flash.pret', [], 'certificat'));

            return $this->redirectToRoute('app_certificate', ['code' => $certificate->getCode()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('certificate/claim.html.twig', [
            'track' => $track,
            'form' => $form,
            'unconfirmed' => null,
            'previous' => $this->certificates->findLatest($user, $track->id),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** La page de vérification : publique, pour un recruteur ou une école. Hors de l'index (SearchIndexing). */
    #[Route('/certificat/{code}', name: 'app_certificate', requirements: ['code' => CertificateCode::PATTERN], methods: ['GET'])]
    public function show(string $code, PageSeo $seo, LinkedinCertification $linkedin, CertificateShare $share, TranslatorInterface $translator): Response
    {
        $certificate = $this->find($code);
        if ($certificate->getCode() !== $code) {
            return $this->redirectToRoute('app_certificate', ['code' => $certificate->getCode()], Response::HTTP_MOVED_PERMANENTLY);
        }
        $url = $this->generateUrl('app_certificate', ['code' => $certificate->getCode()], UrlGeneratorInterface::ABSOLUTE_URL);
        $names = ['parcours' => $certificate->getTrackTitle(), 'titulaire' => $certificate->getHolderName()];
        $seo->setTitle($translator->trans('seo.titre', $names, 'certificat'))
            ->setDescription($translator->trans('seo.description', [...$names, 'exercices' => $certificate->getExerciseCount()], 'certificat'))
            ->setCanonical($url);
        if (!$certificate->isRevoked()) {
            $seo->setImage($share->imageUrl($certificate), $share->card($certificate)->alt(), ShareImageRenderer::WIDTH, ShareImageRenderer::HEIGHT);
        }
        $user = $this->getUser();
        $holder = $user instanceof User && $user->getId() === $certificate->getUser()->getId();

        return $this->render('certificate/show.html.twig', [
            'certificate' => $certificate,
            'holder' => $holder,
            'linkedinUrl' => $holder && !$certificate->isRevoked() ? $linkedin->addToProfileUrl($certificate) : null,
            'share' => $holder && !$certificate->isRevoked() ? $share->links($certificate) : null,
        ]);
    }

    /** L'image d'aperçu du certificat sur les réseaux (voir ShareImage) ; plus d'image pour un certificat révoqué. */
    #[Route('/certificat/{code}/image-de-partage', name: 'app_certificate_image', requirements: ['code' => CertificateCode::PATTERN], methods: ['GET'])]
    public function image(string $code, Request $request, CertificateShare $share, ShareImage $images): BinaryFileResponse
    {
        $certificate = $this->find($code);
        if ($certificate->isRevoked()) {
            throw $this->createNotFoundException('Ce certificat est révoqué.');
        }

        return $images->response($share->card($certificate), $request);
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
