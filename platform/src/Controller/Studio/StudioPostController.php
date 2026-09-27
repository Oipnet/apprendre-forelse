<?php

namespace App\Controller\Studio;

use App\Api\StudioPostInput;
use App\Content\Author\PostDrafter;
use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\Practice;
use App\Content\Track;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les posts LinkedIn tirés d'un parcours ou d'un exercice de Pratique : les deux objets du contenu qui ont une page publique.
 *
 * L'atelier des auteurs est réservé à ROLE_AUTEUR (voir security.yaml) : on écrit sur le disque et on exécute le PHP du pack.
 */
#[Route('/atelier')]
final class StudioPostController extends AbstractController
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly PostDrafter $posts,
    ) {
    }

    /**
     * De quoi tirer un post d'un parcours ou d'un exercice de Pratique : les deux objets du contenu qui ont
     * une page publique. Un chapitre n'en a pas — sa fiche demande un compte — il n'y a donc rien à partager.
     *
     * Déclarée avant `/atelier/{trackId}/{exerciseId}` : « post » n'est pas un identifiant d'exercice utilisable.
     */
    #[Route('/{trackId}/post', name: 'app_studio_post', defaults: ['exerciseId' => null], methods: ['GET'], priority: 2)]
    #[Route('/pratique/{exerciseId}/post', name: 'app_studio_post_pratique', defaults: ['trackId' => null], methods: ['GET'], priority: 3)]
    public function post(?string $trackId, ?string $exerciseId): Response
    {
        $cible = $this->ciblePost($trackId, $exerciseId);

        return $this->render('studio/post.html.twig', [
            'cible' => $cible,
            'config' => [
                'ia' => $this->posts->disponible(),
                // LinkedIn coupe le post à 3 000 caractères, et la première ligne à ~200 : le compteur le rappelle.
                'maximum' => PostDrafter::MAX_CARACTERES,
                'accroche' => PostDrafter::MAX_ACCROCHE,
                'urls' => ['generer' => $cible['generer'], 'publique' => $cible['publique']],
            ],
        ]);
    }

    /** Trois brouillons, un par angle : l'auteur en garde un, le copie, le colle. Rien n'est enregistré. */
    #[Route('/{trackId}/post', name: 'app_studio_post_generer', defaults: ['exerciseId' => null], methods: ['POST'], priority: 2)]
    #[Route('/pratique/{exerciseId}/post', name: 'app_studio_post_generer_pratique', defaults: ['trackId' => null], methods: ['POST'], priority: 3)]
    public function generatePost(?string $trackId, ?string $exerciseId, #[MapRequestPayload] StudioPostInput $input): JsonResponse
    {
        $cible = $this->ciblePost($trackId, $exerciseId);
        $objet = $cible['objet'];

        try {
            $posts = $objet instanceof Practice
                ? $this->posts->pourPratique($objet, $cible['publique'], $input->precision)
                : $this->posts->pourParcours($objet, $cible['publique'], $input->precision);
        } catch (ContentException $e) {
            return $this->json(['erreur' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['posts' => $posts]);
    }

    /**
     * L'objet dont on tire un post, et les adresses qui vont avec : l'exercice de Pratique quand $trackId
     * est nul (route `/atelier/pratique/…`), le parcours sinon.
     *
     * @return array{objet: Track|Practice, titre: string, resume: string, publique: string, retour: string, retourTitre: string, generer: string}
     */
    private function ciblePost(?string $trackId, ?string $exerciseId): array
    {
        if (null === $trackId) {
            $practice = $this->content->findPractice((string) $exerciseId) ?? throw $this->createNotFoundException();

            return [
                'objet' => $practice,
                'titre' => $practice->exercise->title,
                'resume' => $practice->summary,
                'publique' => $this->generateUrl('app_exercise_pratique', ['exerciseId' => $practice->exercise->id], UrlGeneratorInterface::ABSOLUTE_URL),
                'retour' => $this->generateUrl('app_studio_exercise_pratique', ['exerciseId' => $practice->exercise->id]),
                'retourTitre' => $practice->exercise->title,
                'generer' => $this->generateUrl('app_studio_post_generer_pratique', ['exerciseId' => $practice->exercise->id]),
            ];
        }

        $track = $this->content->findTrack($trackId) ?? throw $this->createNotFoundException();

        return [
            'objet' => $track,
            'titre' => $track->title,
            'resume' => $track->description,
            'publique' => $this->generateUrl('app_track', ['trackId' => $track->id], UrlGeneratorInterface::ABSOLUTE_URL),
            'retour' => $this->generateUrl('app_studio_track', ['trackId' => $track->id]),
            'retourTitre' => $track->title,
            'generer' => $this->generateUrl('app_studio_post_generer', ['trackId' => $track->id]),
        ];
    }
}
