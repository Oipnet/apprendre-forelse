<?php

namespace App\Controller\Api;

use App\Entity\Client;
use App\Repository\ClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

class ClientApiController extends AbstractController
{
    /**
     * FAILLE (chapitre 16, F16.1/F16.2) : $this->json($client) sans groupe de
     * sérialisation. Tout sort : motDePasse (hachage), jetonReinitialisation,
     * secret2fa, codesDeSecours, téléphone, adresse.
     *   GET /api/clients/1
     * Correction : #[Groups] en liste blanche + #[Ignore] sur les champs secrets.
     */
    #[Route('/api/clients/{id}', name: 'app_api_client', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function afficher(int $id, ClientRepository $clients): JsonResponse
    {
        $client = $clients->find($id);
        if (null === $client) {
            throw $this->createNotFoundException();
        }

        return $this->json($client);
    }

    /**
     * FAILLE (chapitre 16, F16.3) : PATCH désérialisé dans l'entité, sans
     * ALLOW_EXTRA_ATTRIBUTES=false. Un corps {"roles":["ROLE_ADMIN"],"actif":true}
     * élève le compte. Correction : un DTO d'écriture + #[MapRequestPayload].
     */
    #[Route('/api/clients/{id}', name: 'app_api_client_modifier', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function modifier(int $id, Request $request, ClientRepository $clients, SerializerInterface $serializer, EntityManagerInterface $em): JsonResponse
    {
        $client = $clients->find($id);
        if (null === $client) {
            throw $this->createNotFoundException();
        }

        $serializer->deserialize($request->getContent(), Client::class, 'json', [
            AbstractNormalizer::OBJECT_TO_POPULATE => $client,
        ]);
        $em->flush();

        return $this->json($client);
    }
}
