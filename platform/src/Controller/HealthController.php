<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôle de santé : PHP répond et la base aussi. Le HEALTHCHECK de l'image l'interroge (voir Dockerfile), et c'est
 * lui qu'attend `docker compose up --wait` au déploiement. Ni session ni compte : `stateless` le garantit.
 */
final class HealthController
{
    #[Route('/sante', name: 'app_health', methods: ['GET'], stateless: true)]
    public function __invoke(Connection $connection, LoggerInterface $logger): Response
    {
        try {
            $connection->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable $e) {
            $logger->error('Contrôle de santé : base injoignable.', ['exception' => $e]);

            // Rien du message d'erreur : la route est publique.
            return self::response('base injoignable', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return self::response('ok', Response::HTTP_OK);
    }

    private static function response(string $body, int $status): Response
    {
        return new Response($body."\n", $status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
