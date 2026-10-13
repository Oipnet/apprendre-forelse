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

use function Symfony\Component\Translation\t;

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
            $this->addFlash('danger', t('defense.flash.reservation_expiree', [], 'apprentissage'));
        } else {
            try {
                $this->management->book($user, $defense);
                $startsAt = $defense->getStartsAt();
                $this->addFlash('success', t('defense.flash.reservee', [
                    'date' => self::format($startsAt, $request->getLocale(), \IntlDateFormatter::SHORT, \IntlDateFormatter::NONE),
                    'heure' => self::format($startsAt, $request->getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT),
                ], 'apprentissage'));
            } catch (DefenseRuleViolation $e) {
                $this->addFlash('danger', $e->flash());
            }
        }

        return $this->redirectToRoute('app_track', ['trackId' => $trackId, '_fragment' => 'soutenance']);
    }

    #[Route('/parcours/{trackId}/soutenance/{id}/annuler', name: 'app_defense_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(string $trackId, #[MapEntity(id: 'id')] Defense $defense, Request $request): Response
    {
        $user = $this->learner($trackId, $defense);
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            $this->addFlash('danger', t('defense.flash.annulation_expiree', [], 'apprentissage'));
        } else {
            try {
                $this->management->cancel($user, $defense);
                $this->addFlash('success', t('defense.flash.annulee', [], 'apprentissage'));
            } catch (DefenseRuleViolation $e) {
                $this->addFlash('danger', $e->flash());
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

    /** Une date ou une heure de la soutenance, dans la langue de la page (13/10/2026, 14:30 en français). */
    private static function format(\DateTimeImmutable $date, string $locale, int $dateType, int $timeType): string
    {
        return (string) (new \IntlDateFormatter($locale, $dateType, $timeType, $date->getTimezone()))->format($date);
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
