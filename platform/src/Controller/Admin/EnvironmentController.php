<?php

namespace App\Controller\Admin;

use App\Content\ContentException;
use App\Content\EnvironmentRegistry;
use App\Instance\InstalledEnvironment;
use App\Instance\InstalledEnvironments;
use App\Instance\PackEnvironments;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

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
    public function __construct(
        private readonly EnvironmentRegistry $environments,
        private readonly InstalledEnvironments $installed,
        private readonly PackEnvironments $packEnvironments,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[AdminRoute('/environnements', name: 'environments', options: ['methods' => ['GET']], allowedDashboards: [DashboardController::class])]
    public function index(): Response
    {
        // Un pack mal déclaré ne doit pas emporter la page : c'est justement ici qu'on vient le lire.
        try {
            $demandes = $this->packEnvironments->state();
            $manquants = \count($this->packEnvironments->toInstall());
        } catch (ContentException $e) {
            $demandes = [];
            $manquants = 0;
            $this->addFlash('error', $e->getMessage());
        }

        return $this->render('admin/environments.html.twig', [
            'disponibles' => $this->available(),
            'demandes' => $demandes,
            'manquants' => $manquants,
            'installations' => $this->installed->isEnabled() ? $this->installed->jobs() : [],
            'activable' => $this->installed->isEnabled(),
            'dossier' => $this->installed->directory(),
        ]);
    }

    #[AdminRoute('/environnements/installer', name: 'environments_install', options: ['methods' => ['POST']], allowedDashboards: [DashboardController::class])]
    public function install(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('environments', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $depot = trim((string) $request->request->get('depot'));
        $ref = trim((string) $request->request->get('ref'));

        if (!$this->installed->isEnabled()) {
            $this->addFlash('error', sprintf('Aucun dossier d\'environnements installables : %s n\'existe pas ou n\'est pas écrivable.', $this->installed->directory()));
        } elseif (!str_starts_with($depot, 'https://')) {
            $this->addFlash('error', 'L\'adresse du dépôt doit commencer par « https:// ».');
        } else {
            $this->lancer([$depot, ...('' === $ref ? [] : ['--ref='.$ref])]);
            $this->addFlash('success', 'Installation lancée. Elle dure quelques minutes : rafraîchissez cette page pour suivre.');
        }

        return $this->redirectToRoute('admin_environments');
    }

    /**
     * Installe d'un coup ce que les packs demandent et que l'instance n'a pas.
     *
     * Même tâche de fond que pour une installation seule : la commande enchaîne les dépôts, et la page
     * les voit arriver un par un au fil des rafraîchissements.
     */
    #[AdminRoute('/environnements/synchroniser', name: 'environments_sync', options: ['methods' => ['POST']], allowedDashboards: [DashboardController::class])]
    public function sync(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('environments', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->installed->isEnabled()) {
            $this->addFlash('error', sprintf('Aucun dossier d\'environnements installables : %s n\'existe pas ou n\'est pas écrivable.', $this->installed->directory()));

            return $this->redirectToRoute('admin_environments');
        }
        $this->lancer([], 'app:environnement:synchroniser');
        $this->addFlash('success', 'Installation des environnements demandés par les packs lancée. Rafraîchissez cette page pour suivre.');

        return $this->redirectToRoute('admin_environments');
    }

    #[AdminRoute('/environnements/{id}/mettre-a-jour', name: 'environments_update', options: ['methods' => ['POST'], 'requirements' => ['id' => '[a-z0-9-]+']], allowedDashboards: [DashboardController::class])]
    public function update(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('environments', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->lancer([InstalledEnvironments::id($id)]);
            $this->addFlash('success', sprintf('Mise à jour de « %s » lancée.', $id));
        } catch (ContentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_environments');
    }

    #[AdminRoute('/environnements/installations/{cle}/oublier', name: 'environments_forget', options: ['methods' => ['POST'], 'requirements' => ['cle' => '[a-f0-9]+']], allowedDashboards: [DashboardController::class])]
    public function forget(Request $request, string $cle): Response
    {
        if (!$this->isCsrfTokenValid('environments', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->installed->forgetJob($cle);

        return $this->redirectToRoute('admin_environments');
    }

    #[AdminRoute('/environnements/{id}/retirer', name: 'environments_remove', options: ['methods' => ['POST'], 'requirements' => ['id' => '[a-z0-9-]+']], allowedDashboards: [DashboardController::class])]
    public function remove(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('environments', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
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

    /**
     * Lance la commande d'installation en tâche de fond et rend la main tout de suite : le clone et le
     * `composer install` dépassent de loin le temps d'une requête. Le suivi passe par l'état écrit sur
     * disque, pas par ce processus.
     *
     * @param list<string> $arguments
     */
    private function lancer(array $arguments, string $commandeConsole = 'app:environnement:installer'): void
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';
        $commande = [$php, $this->projectDir.'/bin/console', $commandeConsole, ...$arguments];

        // Detacher pour de vrai. Le destructeur de Process **tue** le processus lancé : à la fin de
        // cette méthode, une installation démarrée par `start()` seul serait coupée net. `setsid --fork`
        // la sort de ce groupe de processus — le fils immédiat rend la main aussitôt, le petit-fils
        // continue seul. Jamais de shell : l'adresse vient d'un formulaire, elle reste un argument.
        $setsid = (new ExecutableFinder())->find('setsid');
        $process = new Process(null === $setsid ? $commande : [$setsid, '--fork', ...$commande], $this->projectDir);
        $process->setTimeout(null);
        $process->disableOutput();
        $process->run();
    }
}
