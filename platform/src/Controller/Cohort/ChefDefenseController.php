<?php

namespace App\Controller\Cohort;

use App\Content\ContentRepository;
use App\Defense\DefenseDossier;
use App\Defense\DefenseExport;
use App\Defense\DefenseManagement;
use App\Defense\DefenseRuleViolation;
use App\Entity\Cohort;
use App\Entity\Defense;
use App\Entity\DefenseLevel;
use App\Entity\User;
use App\Form\DefenseEvaluationType;
use App\Form\DefenseSlotType;
use App\Security\CohortVoter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Les soutenances d'une cohorte, dans l'espace des chefs : ouvrir et supprimer des créneaux, lire le dossier d'un
 * apprenant, l'évaluer, exporter les résultats. Chaque action passe par CohortVoter, comme le reste de /cohorte/{id}.
 */
final class ChefDefenseController extends AbstractController
{
    public function __construct(
        private readonly DefenseManagement $management,
        private readonly ContentRepository $content,
    ) {
    }

    #[AdminRoute('/{id}/soutenances', name: 'cohort_defense_open', options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']], allowedDashboards: [ChefDashboardController::class])]
    #[IsGranted(CohortVoter::MANAGE_PARCOURS, subject: 'cohort')]
    public function open(#[MapEntity(id: 'id')] Cohort $cohort, Request $request): Response
    {
        $form = $this->slotForm($cohort);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $errors = array_map(static fn ($error) => $error->getMessage(), iterator_to_array($form->getErrors(true)));
            $this->addFlash('danger', 'Le créneau n\'a pas été ouvert : '.($errors ? implode(' ', array_unique($errors)) : 'rechargez la page et recommencez.'));

            return $this->toDefenses($cohort);
        }

        $data = $form->getData();
        try {
            $defense = $this->management->openSlot($cohort, $data['trackId'], $data['startsAt'], $data['durationMinutes'], $data['mode'], $data['place']);
        } catch (DefenseRuleViolation $e) {
            $this->addFlash('danger', 'Le créneau n\'a pas été ouvert : '.$e->getMessage());

            return $this->toDefenses($cohort);
        }
        $this->addFlash('success', sprintf('Créneau ouvert le %s.', $defense->getStartsAt()->format('d/m/Y à H:i')));

        return $this->toDefenses($cohort);
    }

    #[AdminRoute('/{id}/soutenances/{defenseId}/supprimer', name: 'cohort_defense_remove', options: ['requirements' => ['id' => '\d+', 'defenseId' => '\d+'], 'methods' => ['POST']], allowedDashboards: [ChefDashboardController::class])]
    #[IsGranted(CohortVoter::MANAGE_PARCOURS, subject: 'cohort')]
    public function remove(#[MapEntity(id: 'id')] Cohort $cohort, #[MapEntity(id: 'defenseId')] Defense $defense, Request $request): Response
    {
        $this->assertBelongs($cohort, $defense);
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            $this->addFlash('danger', 'Le créneau n\'a pas été supprimé : rechargez la page et recommencez.');

            return $this->toDefenses($cohort);
        }
        try {
            $this->management->removeSlot($defense);
            $this->addFlash('success', 'Créneau supprimé.');
        } catch (DefenseRuleViolation $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->toDefenses($cohort);
    }

    /** Le dossier de l'apprenant et la grille d'évaluation. */
    #[AdminRoute('/{id}/soutenances/{defenseId}', name: 'cohort_defense', options: ['requirements' => ['id' => '\d+', 'defenseId' => '\d+'], 'methods' => ['GET']], allowedDashboards: [ChefDashboardController::class])]
    #[IsGranted(CohortVoter::VIEW, subject: 'cohort')]
    public function show(#[MapEntity(id: 'id')] Cohort $cohort, #[MapEntity(id: 'defenseId')] Defense $defense, DefenseDossier $dossier): Response
    {
        $this->assertBelongs($cohort, $defense);
        if (!$defense->isBooked()) {
            $this->addFlash('info', 'Ce créneau n\'est pas encore réservé : il n\'y a pas de dossier à lire.');

            return $this->toDefenses($cohort);
        }

        return $this->render('cohorte/defense.html.twig', [
            'cohort' => $cohort,
            'defense' => $defense,
            'dossier' => $dossier->of($defense),
            'form' => $this->isGranted(CohortVoter::MANAGE_PARCOURS, $cohort) ? $this->evaluationForm($cohort, $defense) : null,
        ]);
    }

    #[AdminRoute('/{id}/soutenances/{defenseId}/evaluation', name: 'cohort_defense_evaluate', options: ['requirements' => ['id' => '\d+', 'defenseId' => '\d+'], 'methods' => ['POST']], allowedDashboards: [ChefDashboardController::class])]
    #[IsGranted(CohortVoter::MANAGE_PARCOURS, subject: 'cohort')]
    public function evaluate(#[MapEntity(id: 'id')] Cohort $cohort, #[MapEntity(id: 'defenseId')] Defense $defense, Request $request): Response
    {
        $this->assertBelongs($cohort, $defense);
        $back = $this->redirectToRoute('chef_cohort_defense', ['id' => $cohort->getId(), 'defenseId' => $defense->getId()]);
        $form = $this->evaluationForm($cohort, $defense);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', 'L\'évaluation n\'a pas été enregistrée : remplissez chaque ligne de la grille et le résultat.');

            return $back;
        }

        $data = $form->getData();
        $user = $this->getUser();
        \assert($user instanceof User);
        try {
            $this->management->evaluate($defense, $user, $this->gradesByChapter($defense, $data['grades']), $data['outcome'], $data['comment']);
        } catch (DefenseRuleViolation $e) {
            $this->addFlash('danger', $e->getMessage());

            return $back;
        }
        $this->addFlash('success', sprintf('Évaluation enregistrée : %s. %s a reçu son résultat par email.', mb_strtolower($defense->getOutcome()?->label() ?? ''), $defense->getLearner()?->getDisplayName()));

        return $back;
    }

    #[AdminRoute('/{id}/soutenances.csv', name: 'cohort_defense_export', options: ['requirements' => ['id' => '\d+'], 'methods' => ['GET']], allowedDashboards: [ChefDashboardController::class])]
    #[IsGranted(CohortVoter::VIEW, subject: 'cohort')]
    public function export(#[MapEntity(id: 'id')] Cohort $cohort, DefenseExport $export): Response
    {
        $response = new Response($export->csv($cohort), 200, ['Content-Type' => 'text/csv; charset=utf-8']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, DefenseExport::filename($cohort)));

        return $response;
    }

    /** @return FormInterface<mixed> */
    private function slotForm(Cohort $cohort): FormInterface
    {
        return $this->createForm(DefenseSlotType::class, ['durationMinutes' => 30], [
            'action' => $this->generateUrl('chef_cohort_defense_open', ['id' => $cohort->getId()]),
            'tracks' => $this->management->tracksOf($cohort),
        ]);
    }

    /** @return FormInterface<mixed> */
    private function evaluationForm(Cohort $cohort, Defense $defense): FormInterface
    {
        $chapters = $this->content->findTrack($defense->getTrackId())->chapters ?? [];
        $grades = $defense->getGrades();
        $data = ['grades' => [], 'outcome' => $defense->getOutcome(), 'comment' => $defense->getComment()];
        foreach ($chapters as $index => $chapter) {
            $data['grades']['c'.$index] = $grades[$chapter->id] ?? null;
        }

        return $this->createForm(DefenseEvaluationType::class, $data, [
            'action' => $this->generateUrl('chef_cohort_defense_evaluate', ['id' => $cohort->getId(), 'defenseId' => $defense->getId()]),
            'chapters' => $chapters,
        ]);
    }

    /**
     * @param array<string, DefenseLevel> $fields par champ « c0 », « c1 »…
     *
     * @return array<string, DefenseLevel> par identifiant de chapitre
     */
    private function gradesByChapter(Defense $defense, array $fields): array
    {
        $grades = [];
        foreach ($this->content->findTrack($defense->getTrackId())->chapters ?? [] as $index => $chapter) {
            if (isset($fields['c'.$index])) {
                $grades[$chapter->id] = $fields['c'.$index];
            }
        }

        return $grades;
    }

    private function assertBelongs(Cohort $cohort, Defense $defense): void
    {
        if ($defense->getCohort()->getId() !== $cohort->getId()) {
            throw $this->createNotFoundException();
        }
    }

    private function toDefenses(Cohort $cohort): Response
    {
        return $this->redirectToRoute('chef_cohort', ['id' => $cohort->getId(), '_fragment' => 'soutenances']);
    }
}
