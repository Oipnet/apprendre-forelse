<?php

namespace App\Controller;

use App\Instance\EnvironmentArtifacts;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sert les archives des environnements que l'instance a installés elle-même.
 *
 * Les environnements livrés avec le moteur vivent dans `public/envs` : le serveur web les sert sans
 * passer par PHP, et cette route ne les voit jamais (FrankenPHP ne délègue à l'application que ce
 * qu'il ne trouve pas sur le disque). Même espace d'URL, donc, et rien à changer côté navigateur.
 */
final class EnvironmentArtifactController extends AbstractController
{
    public function __construct(private readonly EnvironmentArtifacts $artifacts)
    {
    }

    #[Route('/envs/{fichier}', name: 'app_environment_artifact', methods: ['GET'], requirements: ['fichier' => '[a-z0-9.-]+'])]
    public function artifact(string $fichier): BinaryFileResponse
    {
        $chemin = $this->artifacts->path($fichier) ?? throw $this->createNotFoundException();

        $reponse = new BinaryFileResponse($chemin);
        $reponse->setPublic();
        // L'URL porte la date de construction (voir ExercisePayloadFactory) : une archive refaite change d'URL.
        $reponse->setMaxAge(86400);

        return $reponse;
    }
}
