<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Theme\ActiveTheme;
use App\Theme\ThemeActivation;
use App\Theme\ThemeActivationException;
use App\Theme\ThemeCatalog;
use App\Theme\ThemeChecker;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Les thèmes installés, et le choix du thème actif.
 *
 * On choisit parmi les thèmes déposés dans THEMES_DIR (monté en lecture seule) : rien ne se téléverse ici. Les gabarits
 * et les scripts d'un thème s'exécutent avec les droits de la plateforme, un téléversement ferait de cette page une
 * porte d'entrée pour du code.
 *
 * L'aperçu montre un thème à l'administrateur seul, dans sa session, avant de l'activer pour tout le monde.
 */
final class ThemeAdminController extends AbstractController
{
    public function __construct(
        private readonly ThemeCatalog $catalog,
        private readonly ThemeChecker $checker,
        private readonly ActiveTheme $active,
        private readonly ThemeActivation $activation,
    ) {
    }

    #[AdminRoute('/themes', name: 'themes', options: ['methods' => ['GET']], allowedDashboards: [DashboardController::class])]
    public function index(Request $request): Response
    {
        $session = $request->hasPreviousSession() ? $request->getSession() : null;

        return $this->render('admin/themes.html.twig', [
            'themes' => array_map($this->checker->inspect(...), array_keys($this->catalog->all())),
            'ignores' => $this->catalog->ignored(),
            'dossier' => $this->catalog->themesDirectory(),
            'actif' => $this->active->chosen(),
            'introuvable' => $this->active->missing(),
            'apercu' => $session?->get(ActiveTheme::PREVIEW_SESSION),
        ]);
    }

    #[AdminRoute('/themes/{id}/apercu', name: 'themes_preview', options: ['methods' => ['POST'], 'requirements' => ['id' => '[a-z0-9-]+']], allowedDashboards: [DashboardController::class])]
    #[IsCsrfTokenValid('themes', tokenKey: '_token')]
    public function preview(string $id, Request $request): Response
    {
        $errors = $this->checker->errors($id);
        if ([] !== $errors) {
            $this->addFlash('error', ThemeActivationException::invalid($id, $errors)->getMessage());

            return $this->redirectToRoute('admin_themes');
        }
        $request->getSession()->set(ActiveTheme::PREVIEW_SESSION, $id);

        return $this->redirectToRoute('app_home');
    }

    #[AdminRoute('/themes/apercu/quitter', name: 'themes_preview_quit', options: ['methods' => ['POST']], allowedDashboards: [DashboardController::class])]
    #[IsCsrfTokenValid('themes', tokenKey: '_token')]
    public function quitPreview(Request $request): Response
    {
        $request->getSession()->remove(ActiveTheme::PREVIEW_SESSION);

        return $this->redirectToRoute('admin_themes');
    }

    #[AdminRoute('/themes/{id}/activer', name: 'themes_activate', options: ['methods' => ['POST'], 'requirements' => ['id' => '[a-z0-9-]+']], allowedDashboards: [DashboardController::class])]
    #[IsCsrfTokenValid('themes', tokenKey: '_token')]
    public function activate(string $id, Request $request): Response
    {
        $user = $this->getUser();
        try {
            $this->activation->activate($id, $user instanceof User ? $user->getEmail() : null);
            $request->getSession()->remove(ActiveTheme::PREVIEW_SESSION);
            $this->addFlash('success', sprintf('Thème « %s » activé : le site l\'affiche dès maintenant.', $id));
        } catch (ThemeActivationException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_themes');
    }
}
