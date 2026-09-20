<?php

namespace App\Controller\Admin;

use App\Admin\BetaStats;
use App\Instance\Branding;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

/** Tableau de bord de la bêta : cohortes, progression, retours. Réservé à ROLE_ADMIN (security.yaml). */
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(private readonly BetaStats $stats, private readonly Branding $branding)
    {
    }

    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', ['cohorts' => $this->stats->cohorts()]);
    }

    /**
     * Grille apprenant × exercice d'une cohorte (« - » pour les comptes sans cohorte). Limitée à ce tableau de bord :
     * sans cela, EasyAdmin la créerait aussi sous /cohorte, ouverte aux chefs pour toutes les cohortes.
     */
    #[AdminRoute('/cohorte/{cohort}', name: 'cohort', allowedDashboards: [self::class])]
    public function cohort(string $cohort): Response
    {
        $stats = $this->stats->cohort($cohort) ?? throw $this->createNotFoundException();

        return $this->render('admin/cohort.html.twig', ['cohort' => $stats]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle($this->branding->signature())
            ->setFaviconPath($this->branding->iconUrl() ?? 'img/favicon.svg')
            ->renderContentMaximized();
    }

    /** Panneaux et tableaux des pages maison, bâtis sur les variables du thème (clair et sombre). */
    public function configureAssets(): Assets
    {
        return Assets::new()->addCssFile('css/admin.css');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Avancement', 'fa fa-chart-simple');
        yield MenuItem::linkToUrl('Mes cohortes', 'fa fa-chalkboard-user', $this->generateUrl('chef'));
        yield MenuItem::section('Bêta');
        yield MenuItem::linkTo(CohortCrudController::class, 'Cohortes', 'fa fa-people-group');
        yield MenuItem::linkTo(UserCrudController::class, 'Apprenants', 'fa fa-users');
        yield MenuItem::linkTo(ExerciseProgressCrudController::class, 'Progression', 'fa fa-list-check');
        yield MenuItem::linkTo(FeedbackCrudController::class, 'Retours', 'fa fa-comments');
        yield MenuItem::linkTo(ContactMessageCrudController::class, 'Messages', 'fa fa-inbox');
        yield MenuItem::section('Ventes');
        yield MenuItem::linkTo(TrackPricingCrudController::class, 'Tarifs', 'fa fa-tag');
        yield MenuItem::linkTo(PurchaseCrudController::class, 'Achats', 'fa fa-receipt');
        yield MenuItem::linkTo(TrackAccessCrudController::class, 'Accès', 'fa fa-key');
        yield MenuItem::section('Lancement');
        yield MenuItem::linkTo(TrackSeoCrudController::class, 'Référencement', 'fa fa-magnifying-glass');
        yield MenuItem::linkTo(WaitlistEntryCrudController::class, 'Liste d\'attente', 'fa fa-envelope');
        yield MenuItem::section();
        yield MenuItem::linkToRoute('Retour au site', 'fa fa-arrow-left', 'app_home');
    }
}
