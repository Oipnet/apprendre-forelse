<?php

namespace App\Controller\Cohort;

use App\Admin\BetaStats;
use App\Cohort\CohortAccessSync;
use App\Cohort\CohortQuoteEstimator;
use App\Content\ContentRepository;
use App\Entity\Cohort;
use App\Entity\User;
use App\Form\CohortHeadcountType;
use App\Form\CohortTracksType;
use App\Repository\CohortRepository;
use App\Security\CohortVoter;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace des chefs de cohorte : leurs cohortes, leur avancement, et le choix des parcours proposés.
 * Tableau de bord distinct de /admin : aucune page CRUD n'y est exposée (allowedControllers vide).
 * Réservé à ROLE_CHEF_COHORTE (security.yaml) ; chaque cohorte passe en plus par CohortVoter.
 */
#[AdminDashboard(routePath: '/cohorte', routeName: 'chef', allowedControllers: [])]
final class ChefDashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly CohortRepository $cohorts,
        private readonly BetaStats $stats,
        private readonly ContentRepository $content,
        private readonly CohortQuoteEstimator $estimator,
        private readonly CohortAccessSync $cohortAccess,
    ) {
    }

    /** Mes cohortes (toutes, pour un administrateur). */
    public function index(): Response
    {
        $user = $this->getUser();
        $cohorts = $this->isGranted(User::ROLE_ADMIN) ? $this->cohorts->findAllOrdered() : ($user instanceof User ? $this->cohorts->findByChef($user) : []);

        return $this->render('cohorte/index.html.twig', [
            'cohorts' => $cohorts,
            'learners' => $this->cohorts->countUsers($cohorts),
            'tracks' => $this->content->tracks(),
        ]);
    }

    #[AdminRoute('/{id}', name: 'cohort', options: ['requirements' => ['id' => '\d+'], 'methods' => ['GET']], allowedDashboards: [self::class])]
    #[IsGranted(CohortVoter::VIEW, subject: 'cohort')]
    public function cohort(#[MapEntity(id: 'id')] Cohort $cohort): Response
    {
        return $this->render('cohorte/show.html.twig', [
            'cohort' => $this->stats->of($cohort),
            'form' => $this->tracksForm($cohort),
            'tracks' => $this->content->tracks(),
            'inProgress' => $this->stats->learnersInProgress($cohort),
            'canManage' => $this->isGranted(CohortVoter::MANAGE_PARCOURS, $cohort),
            // Financement : en lecture pour le chef, sauf l'effectif ; aucune action de paiement ici.
            'quote' => $this->estimator->estimate($cohort),
            'headcountForm' => $this->headcountForm($cohort),
        ]);
    }

    /**
     * Enregistre les parcours proposés. Les parcours en préparation ne se cochent que par un administrateur :
     * pour un chef, leur case est désactivée et leur état d'origine conservé.
     */
    #[AdminRoute('/{id}/parcours', name: 'cohort_tracks', options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']], allowedDashboards: [self::class])]
    #[IsGranted(CohortVoter::MANAGE_PARCOURS, subject: 'cohort')]
    public function tracks(#[MapEntity(id: 'id')] Cohort $cohort, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->tracksForm($cohort);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', 'Les parcours n\'ont pas été enregistrés : rechargez la page et recommencez.');

            return $this->redirectToRoute('chef_cohort', ['id' => $cohort->getId()]);
        }

        $locked = $this->lockedTrackIds();
        $chosen = array_diff($form->get('trackIds')->getData(), $locked);
        $kept = array_intersect($cohort->getAvailableTrackIds(), $locked);
        // Ordre du catalogue, pour un affichage stable.
        $selection = array_values(array_filter(array_keys($this->content->tracks()), static fn (string $id) => \in_array($id, [...$chosen, ...$kept], true)));
        if (!$selection && $cohort->isFundedByInstitution()) {
            $this->addFlash('danger', 'Une cohorte financée par l\'établissement propose au moins un parcours : rien n\'a été enregistré.');

            return $this->redirectToRoute('chef_cohort', ['id' => $cohort->getId()]);
        }
        $cohort->setAvailableTrackIds($selection);
        $entityManager->flush();
        // Financée par l'établissement : accès ouverts pour les parcours ajoutés, révoqués pour les parcours retirés.
        $this->cohortAccess->sync($cohort);

        $this->addFlash('success', $cohort->hasTrackSelection()
            ? sprintf('Parcours de « %s » enregistrés : %s.', $cohort->getName(), implode(', ', array_map(fn (string $id) => $this->content->findTrack($id)?->title ?? $id, $cohort->getAvailableTrackIds())))
            : sprintf('Aucun parcours coché : « %s » propose tous les parcours.', $cohort->getName()));

        return $this->redirectToRoute('chef_cohort', ['id' => $cohort->getId()]);
    }

    /** L'effectif prévu, base de l'estimation du devis : seul champ du financement ouvert au chef. */
    #[AdminRoute('/{id}/effectif', name: 'cohort_headcount', options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']], allowedDashboards: [self::class])]
    #[IsGranted(CohortVoter::MANAGE_PARCOURS, subject: 'cohort')]
    public function headcount(#[MapEntity(id: 'id')] Cohort $cohort, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->headcountForm($cohort);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $cohort->setExpectedHeadcount($form->get('expectedHeadcount')->getData());
            $entityManager->flush();
            $this->addFlash('success', sprintf('Effectif prévu de « %s » : %d.', $cohort->getName(), $cohort->getExpectedHeadcount()));
        } else {
            $this->addFlash('danger', 'L\'effectif n\'a pas été enregistré : indiquez un nombre entre 0 et 10 000.');
        }

        return $this->redirectToRoute('chef_cohort', ['id' => $cohort->getId(), '_fragment' => 'financement']);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Forelse · cohortes')
            ->setFaviconPath('img/favicon.svg')
            ->renderContentMaximized();
    }

    public function configureAssets(): Assets
    {
        return Assets::new()->addCssFile('css/admin.css');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Mes cohortes', 'fa fa-people-group');
        if ($this->isGranted(User::ROLE_ADMIN)) {
            yield MenuItem::linkToUrl('Administration', 'fa fa-gauge', $this->generateUrl('admin'));
        }
        yield MenuItem::section();
        yield MenuItem::linkToUrl('Retour au site', 'fa fa-arrow-left', $this->generateUrl('app_home'));
    }

    /** @return \Symfony\Component\Form\FormInterface<array{trackIds: list<string>}> */
    private function tracksForm(Cohort $cohort): \Symfony\Component\Form\FormInterface
    {
        return $this->createForm(CohortTracksType::class, ['trackIds' => $cohort->getAvailableTrackIds()], [
            'action' => $this->generateUrl('chef_cohort_tracks', ['id' => $cohort->getId()]),
            'tracks' => $this->content->tracks(),
            'locked' => $this->lockedTrackIds(),
        ]);
    }

    /** @return \Symfony\Component\Form\FormInterface<array{expectedHeadcount: int}> */
    private function headcountForm(Cohort $cohort): \Symfony\Component\Form\FormInterface
    {
        return $this->createForm(CohortHeadcountType::class, ['expectedHeadcount' => $cohort->getExpectedHeadcount()], [
            'action' => $this->generateUrl('chef_cohort_headcount', ['id' => $cohort->getId()]),
        ]);
    }

    /** @return list<string> parcours dont la case ne peut pas changer : ceux en préparation, sauf pour un administrateur */
    private function lockedTrackIds(): array
    {
        if ($this->isGranted(User::ROLE_ADMIN)) {
            return [];
        }

        return array_values(array_map(static fn ($track) => $track->id, array_filter($this->content->tracks(), static fn ($track) => $track->isRestricted())));
    }
}
