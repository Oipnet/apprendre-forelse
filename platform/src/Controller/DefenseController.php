<?php

namespace App\Controller;

use App\Content\TrackVisibility;
use App\Defense\DefenseCalendar;
use App\Defense\DefenseManagement;
use App\Defense\DefenseRuleViolation;
use App\Entity\Defense;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** La soutenance de fin de parcours, côté apprenant : réserver un créneau, l'annuler, l'ajouter à son agenda. */
#[IsGranted('ROLE_USER')]
final class DefenseController extends AbstractController
{
    public function __construct(
        private readonly DefenseManagement $management,
        private readonly TrackVisibility $visibility,
    ) {
    }

    #[Route('/parcours/{trackId}/soutenance/{id}/reserver', name: 'app_defense_book', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function book(string $trackId, #[MapEntity(id: 'id')] Defense $defense, Request $request): Response
    {
        $user = $this->learner($trackId, $defense);
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            $this->addFlash('danger', 'Le créneau n\'a pas été réservé : rechargez la page et recommencez.');
        } else {
            try {
                $this->management->book($user, $defense);
                $this->addFlash('success', sprintf('Soutenance réservée le %s. Votre convocation part par email, avec l\'invitation d\'agenda.', $defense->getStartsAt()->format('d/m/Y à H:i')));
            } catch (DefenseRuleViolation $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_track', ['trackId' => $trackId, '_fragment' => 'soutenance']);
    }

    #[Route('/parcours/{trackId}/soutenance/{id}/annuler', name: 'app_defense_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(string $trackId, #[MapEntity(id: 'id')] Defense $defense, Request $request): Response
    {
        $user = $this->learner($trackId, $defense);
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            $this->addFlash('danger', 'Le créneau n\'a pas été annulé : rechargez la page et recommencez.');
        } else {
            try {
                $this->management->cancel($user, $defense);
                $this->addFlash('success', 'Créneau annulé : vous pouvez en choisir un autre.');
            } catch (DefenseRuleViolation $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_track', ['trackId' => $trackId, '_fragment' => 'soutenance']);
    }

    /** L'invitation d'agenda de sa soutenance, la même que celle de la convocation. */
    #[Route('/parcours/{trackId}/soutenance/{id}/agenda.ics', name: 'app_defense_calendar', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function calendar(string $trackId, #[MapEntity(id: 'id')] Defense $defense, DefenseCalendar $calendar): Response
    {
        $user = $this->learner($trackId, $defense);
        if ($defense->getLearner()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }
        $track = $this->visibility->find($trackId) ?? throw $this->createNotFoundException();
        $response = new Response($calendar->ics($defense, $track), 200, ['Content-Type' => 'text/calendar; charset=utf-8']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, DefenseCalendar::filename($defense)));

        return $response;
    }

    /** L'apprenant connecté, sur un créneau du parcours de l'adresse (sinon 404 : rien à voir ici). */
    private function learner(string $trackId, Defense $defense): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        if ($defense->getTrackId() !== $trackId || null === $this->visibility->find($trackId)) {
            throw $this->createNotFoundException();
        }

        return $user;
    }
}
