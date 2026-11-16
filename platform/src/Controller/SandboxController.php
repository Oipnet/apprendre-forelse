<?php

namespace App\Controller;

use App\Security\SandboxOrigin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Relais d'aperçu, servi uniquement sur l'origine bac à sable (voir OriginIsolationListener). */
final class SandboxController extends AbstractController
{
    #[Route(SandboxOrigin::RELAY_PATH, name: 'app_sandbox', methods: ['GET'])]
    public function relay(SandboxOrigin $sandbox, #[Autowire('%kernel.project_dir%/public/preview-sw.js')] string $serviceWorker): Response
    {
        // L'empreinte du Service Worker entre dans son adresse : un déploiement qui le modifie l'installe aussitôt.
        $version = is_file($serviceWorker) ? substr(hash_file('xxh3', $serviceWorker), 0, 12) : '';

        return $this->render('sandbox.html.twig', ['host_origin' => $sandbox->platformOrigin, 'sw_version' => $version]);
    }
}
