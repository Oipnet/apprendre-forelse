<?php

namespace App\Controller\Api;

use App\Repository\BiereRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * FAILLE (chapitre 8, F8.2) : /api n'est pas protégé par access_control.
 * FAILLE (chapitre 16, F16.4) : aucun limiteur de débit.
 */
class BiereApiController extends AbstractController
{
    #[Route('/api/bieres', name: 'app_api_bieres', methods: ['GET'])]
    public function index(BiereRepository $bieres): JsonResponse
    {
        return $this->json($bieres->catalogue());
    }
}
