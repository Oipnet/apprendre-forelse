<?php

namespace App\Controller\Admin;

use App\Content\ContentException;
use App\Content\EnvironmentRegistry;
use App\Instance\EnvironmentJobLauncher;
use App\Instance\InstallationJobs;
use App\Instance\InstalledEnvironment;
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
 * L'installation dure des minutes (clone, `composer install`, archive) : la page ne l'attend pas, elle
 * lance la commande en tâche de fond et revient. L'état vit dans le dossier de l'environnement, donc un
 * rafraîchissement suffit à voir où il en est, même après un redémarrage du conteneur.
 */
final class EnvironmentController extends AbstractController
{
    private const string INSTALL = 'app:environnement:installer';

    public function __construct(
        private readonly EnvironmentRegistry $environments,
        private readonly InstalledEnvironments $installed,
        private readonly InstallationJobs $jobs,
        private readonly PackEnvironments $packEnvironments,
        private readonly EnvironmentJobLauncher $launcher,
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
            'disponibles' => $this->available(),
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
        $depot = trim((string) $request->request->get('depot'));
        $ref = trim((string) $request->request->get('ref'));
        $dossier = trim((string) $request->request->get('dossier'));

        if (!$this->installed->isEnabled()) {
            $this->addFlash('error', sprintf('Aucun dossier d\'environnements installables : %s n\'existe pas ou n\'est pas écrivable.', $this->installed->directory()));
        } elseif (!str_starts_with($depot, 'https://')) {
            $this->addFlash('error', 'L\'adresse du dépôt doit commencer par « https:// ».');
        } else {
            $this->launcher->launch(self::INSTALL, [
                $depot,
                ...('' === $ref ? [] : ['--ref='.$ref]),
                ...('' === $dossier ? [] : ['--dossier='.$dossier]),
            ]);
            $this->addFlash('success', 'Installation lancée. Elle dure quelques minutes : rafraîchissez cette page pour suivre.');
        }

        return $this->redirectToRoute('admin_environments');
    }

    /**
     * Empaquette d'un coup les environnements que les packs portent et qui n'ont pas d'archive.
     *
     * Même tâche de fond que pour une installation seule : la commande les enchaîne, et la page les
     * voit arriver un par un au fil des rafraîchissements.
     */
    #[AdminRoute('/environnements/synchroniser', name: 'environments_sync', options: ['methods' => ['POST']], allowedDashboards: [DashboardController::class])]
    #[IsCsrfTokenValid('environments', tokenKey: '_token')]
    public function sync(): Response
    {
        if (!$this->installed->isEnabled()) {
            $this->addFlash('error', sprintf('Aucun dossier d\'environnements installables : %s n\'existe pas ou n\'est pas écrivable.', $this->installed->directory()));

            return $this->redirectToRoute('admin_environments');
        }
        $this->launcher->launch('app:environnement:synchroniser', []);
        $this->addFlash('success', 'Empaquetage des environnements portés par les packs lancé. Rafraîchissez cette page pour suivre.');

        return $this->redirectToRoute('admin_environments');
    }

    #[AdminRoute('/environnements/{id}/mettre-a-jour', name: 'environments_update', options: ['methods' => ['POST'], 'requirements' => ['id' => '[a-z0-9-]+']], allowedDashboards: [DashboardController::class])]
    #[IsCsrfTokenValid('environments', tokenKey: '_token')]
    public function update(string $id): Response
    {
        try {
            $this->launcher->launch(self::INSTALL, [InstalledEnvironments::id($id)]);
            $this->addFlash('success', sprintf('Mise à jour de « %s » lancée.', $id));
        } catch (ContentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_environments');
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
        try {
            $this->installed->remove($id);
            $this->environments->reset();
            $this->addFlash('success', sprintf('Environnement « %s » retiré. Les exercices qui le désignaient ne se chargeront plus.', $id));
        } catch (ContentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_environments');
    }

    /**
     * Les environnements que le moteur sait charger, avec leur source quand ils viennent d'un dépôt.
     *
     * @return list<array{id: string, title: string, framework: string, compose: bool, source: InstalledEnvironment|null}>
     */
    private function available(): array
    {
        $installes = $this->installed->all();
        $lignes = [];
        foreach ($this->environments->all() as $id => $environment) {
            $lignes[] = [
                'id' => $id,
                'title' => $environment->title,
                'framework' => $environment->framework->label,
                'compose' => $environment->isComposed(),
                'source' => $installes[$id] ?? null,
            ];
        }

        return $lignes;
    }
}
