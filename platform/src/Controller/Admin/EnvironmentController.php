<?php

namespace App\Controller\Admin;

use App\Content\ContentException;
use App\Instance\EnvironmentInstallation;
use App\Instance\InstallationJobs;
use App\Instance\InstalledEnvironments;
use App\Instance\PackEnvironments;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Les environnements d'exécution : ceux que le moteur livre, et ceux que l'instance a installés depuis
 * un dépôt Git.
 *
 * L'installation part en tâche de fond (voir EnvironmentInstallation) : la page ne l'attend pas. L'état vit dans
 * le dossier de l'environnement, donc un rafraîchissement suffit à voir où il en est, même après un redémarrage.
 */
final class EnvironmentController extends AbstractController
{
    public function __construct(
        private readonly EnvironmentInstallation $installation,
        private readonly InstalledEnvironments $installed,
        private readonly InstallationJobs $jobs,
        private readonly PackEnvironments $packEnvironments,
    ) {
    }

    #[AdminRoute('/environnements', name: 'environments', options: ['methods' => ['GET']], allowedDashboards: [DashboardController::class])]
    public function index(): Response
    {
        // Un pack mal formé ne doit pas emporter la page : c'est justement ici qu'on vient le lire.
        try {
            $portes = $this->packEnvironments->state();
            $aEmpaqueter = \count($this->packEnvironments->toBuild());
        } catch (ContentException $e) {
            $portes = [];
            $aEmpaqueter = 0;
            $this->addFlash('error', $e->getMessage());
        }

        return $this->render('admin/environments.html.twig', [
            'disponibles' => $this->installation->available(),
            'portes' => $portes,
            'aEmpaqueter' => $aEmpaqueter,
            'installations' => $this->installed->isEnabled() ? $this->jobs->all() : [],
            'activable' => $this->installed->isEnabled(),
            'dossier' => $this->installed->directory(),
        ]);
    }

    #[AdminRoute('/environnements/installer', name: 'environments_install', options: ['methods' => ['POST']], allowedDashboards: [DashboardController::class])]
    #[IsCsrfTokenValid('environments', tokenKey: '_token')]
    public function install(Request $request): Response
    {
        return $this->launch(
            fn () => $this->installation->install($request->request->getString('depot'), $request->request->getString('ref'), $request->request->getString('dossier')),
            'Installation lancée. Elle dure quelques minutes : rafraîchissez cette page pour suivre.',
        );
    }

    /** Même tâche de fond que pour une installation seule : la page voit les environnements arriver un par un. */
    #[AdminRoute('/environnements/synchroniser', name: 'environments_sync', options: ['methods' => ['POST']], allowedDashboards: [DashboardController::class])]
    #[IsCsrfTokenValid('environments', tokenKey: '_token')]
    public function sync(): Response
    {
        return $this->launch(
            $this->installation->syncPackEnvironments(...),
            'Empaquetage des environnements portés par les packs lancé. Rafraîchissez cette page pour suivre.',
        );
    }

    #[AdminRoute('/environnements/{id}/mettre-a-jour', name: 'environments_update', options: ['methods' => ['POST'], 'requirements' => ['id' => '[a-z0-9-]+']], allowedDashboards: [DashboardController::class])]
    #[IsCsrfTokenValid('environments', tokenKey: '_token')]
    public function update(string $id): Response
    {
        return $this->launch(fn () => $this->installation->update($id), sprintf('Mise à jour de « %s » lancée.', $id));
    }

    #[AdminRoute('/environnements/installations/{cle}/oublier', name: 'environments_forget', options: ['methods' => ['POST'], 'requirements' => ['cle' => '[a-f0-9]+']], allowedDashboards: [DashboardController::class])]
    #[IsCsrfTokenValid('environments', tokenKey: '_token')]
    public function forget(string $cle): Response
    {
        $this->jobs->forget($cle);

        return $this->redirectToRoute('admin_environments');
    }

    #[AdminRoute('/environnements/{id}/retirer', name: 'environments_remove', options: ['methods' => ['POST'], 'requirements' => ['id' => '[a-z0-9-]+']], allowedDashboards: [DashboardController::class])]
    #[IsCsrfTokenValid('environments', tokenKey: '_token')]
    public function remove(string $id): Response
    {
        return $this->launch(fn () => $this->installation->remove($id), sprintf('Environnement « %s » retiré. Les exercices qui le désignaient ne se chargeront plus.', $id));
    }

    /** Lance l'opération, dit ce qu'il en est, et revient à la liste. */
    private function launch(callable $operation, string $success): Response
    {
        try {
            $operation();
            $this->addFlash('success', $success);
        } catch (ContentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_environments');
    }
}
