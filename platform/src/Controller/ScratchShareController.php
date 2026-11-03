<?php

namespace App\Controller;

use App\Scratch\ScratchFramework;
use App\Scratch\ScratchShareDuration;
use App\Scratch\ScratchShortLinks;
use App\Security\ClientKey;
use App\Share\ShareLinks;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les liens courts du bac à sable (/bac-a-sable/s/<id>), au choix du visiteur dans la boîte de partage : le code est
 * alors gardé sur le serveur (ScratchShortLinks). Sans la case, le lien long garde tout dans l'adresse et rien n'est
 * stocké. Contrat côté page : playground/src/app/scratch.ts (createShortLink, deleteShortLink).
 */
final class ScratchShareController extends AbstractController
{
    /** D'où vient la visite quand le lien court arrive sans utm_source : copié tel quel depuis la boîte de partage. */
    private const array DEFAULT_UTM = ['utm_source' => 'lien', 'utm_medium' => ShareLinks::MEDIUM, 'utm_campaign' => 'bac-a-sable'];

    public function __construct(private readonly ScratchShortLinks $links)
    {
    }

    /** Ouvre le lien court : la page du bac à sable, le code après le #, comme un lien long (le code ne s'exécute qu'au clic). */
    #[Route(['fr' => '/bac-a-sable/s/{id}', 'en' => '/en/playground/s/{id}'], name: 'app_scratch_short', requirements: ['id' => '[0-9a-zA-Z]{8}'], methods: ['GET'])]
    public function open(string $id, Request $request): Response
    {
        $share = $this->links->find($id);
        if (null === $share) {
            $response = $this->render('scratch/lien_expire.html.twig', [], new Response(status: Response::HTTP_GONE));
        } else {
            // Les paramètres du lien (utm_source=linkedin…) suivent ; sans eux, la visite vient d'un lien copié.
            $query = $request->query->has('utm_source') ? $request->query->all() : self::DEFAULT_UTM;
            $response = new RedirectResponse($this->generateUrl('app_scratch_'.$share->getFramework()->value, $query).'#'.$share->getCode());
        }
        // Un lien court n'a rien à faire dans un moteur de recherche : la page du bac à sable s'indexe, pas ses copies.
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    /** Crée un lien court : corps {code: "code=…", duree: "1m"|"6m"|"1a"|"jamais"}. */
    #[Route(['fr' => '/api/bac-a-sable/{framework}/liens', 'en' => '/en/api/bac-a-sable/{framework}/liens'], name: 'api_scratch_share', methods: ['POST'], format: 'json')]
    public function create(
        ScratchFramework $framework,
        Request $request,
        TranslatorInterface $translator,
        #[Autowire(service: 'limiter.scratch_share')]
        RateLimiterFactoryInterface $limiter,
    ): JsonResponse {
        if (!$limiter->create(ClientKey::of($request->getClientIp()))->consume()->isAccepted()) {
            throw new HttpException(Response::HTTP_TOO_MANY_REQUESTS, 'Too many short links: share the long link for now.');
        }
        $body = self::body($request);
        $duration = ScratchShareDuration::tryFrom(\is_string($body['duree'] ?? null) ? $body['duree'] : ScratchShareDuration::Never->value)
            ?? throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, 'Unknown duration.');
        try {
            $created = $this->links->create($framework, \is_string($body['code'] ?? null) ? $body['code'] : '', $duration);
        } catch (\InvalidArgumentException $e) {
            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage(), $e);
        }
        $url = $this->generateUrl('app_scratch_short', ['id' => $created->share->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->json([
            'id' => $created->share->getId(),
            'url' => $url,
            'expiresAt' => $created->share->getExpiresAt()?->format(\DATE_ATOM),
            'deleteKey' => $created->deleteKey,
            'deleteUrl' => $this->generateUrl('api_scratch_share_delete', ['id' => $created->share->getId()]),
            'share' => self::shareLinks($url, $translator->trans('bac_a_sable.partage.texte', ['framework' => $framework->label()], 'apprentissage')),
        ], Response::HTTP_CREATED);
    }

    /** Supprime un lien court : corps {cle: "…"}, la clé reçue à la création. 404 pour un lien inconnu comme pour une mauvaise clé. */
    #[Route(['fr' => '/api/bac-a-sable/liens/{id}', 'en' => '/en/api/bac-a-sable/liens/{id}'], name: 'api_scratch_share_delete', requirements: ['id' => '[0-9a-zA-Z]{8}'], methods: ['DELETE'], format: 'json')]
    public function delete(string $id, Request $request): Response
    {
        $key = self::body($request)['cle'] ?? null;
        if (!\is_string($key) || !$this->links->delete($id, $key)) {
            throw $this->createNotFoundException('No such link.');
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /** @return array<mixed> */
    private static function body(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, 'Expected a JSON body.');
        }
    }

    /**
     * Les liens de partage du lien court. Le lien à copier reste nu (le plus court possible) : la redirection ajoute
     * elle-même d'où vient la visite (DEFAULT_UTM).
     *
     * @return array<string, string>
     */
    private static function shareLinks(string $url, string $text): array
    {
        return ['lien' => $url, 'natif' => $url.'?utm_source=natif'] + ShareLinks::of($url, $text, 'bac-a-sable');
    }
}
