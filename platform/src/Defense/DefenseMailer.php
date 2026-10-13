<?php

namespace App\Defense;

use App\Content\Track;
use App\Entity\Defense;
use App\Mail\Sender;
use App\Theme\Theme;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/** La convocation (avec l'invitation d'agenda) et le résultat d'une soutenance, envoyés à l'apprenant. */
final readonly class DefenseMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private Sender $sender,
        private Theme $theme,
        private DefenseCalendar $calendar,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
    ) {
    }

    /** N'échoue jamais : le créneau est réservé, et reste affiché sur la page du parcours. */
    public function sendConvocation(Defense $defense, Track $track): void
    {
        $startsAt = $defense->getStartsAt();
        $subject = $this->translator->trans('sujet.defense_convocation', [
            'parcours' => $track->title,
            'date' => $this->format($startsAt, \IntlDateFormatter::SHORT, \IntlDateFormatter::NONE),
            'heure' => $this->format($startsAt, \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT),
        ], 'emails');
        $this->send($defense, $track, $subject, 'emails/defense_convocation', true);
    }

    /** N'échoue jamais : le résultat est enregistré, et affiché sur la page du parcours. */
    public function sendResult(Defense $defense, Track $track): void
    {
        $this->send($defense, $track, $this->translator->trans('sujet.defense_result', ['parcours' => $track->title, 'site' => $this->theme->name()], 'emails'), 'emails/defense_result', false);
    }

    /** Une date ou une heure dans la langue de l'email (format_date('short') des gabarits), dans le fuseau du créneau. */
    private function format(\DateTimeImmutable $date, int $dateType, int $timeType): string
    {
        return (string) \IntlDateFormatter::create($this->translator->getLocale(), $dateType, $timeType, $date->getTimezone())->format($date);
    }

    private function send(Defense $defense, Track $track, string $subject, string $template, bool $withCalendar): void
    {
        $learner = $defense->getLearner();
        if (null === $learner) {
            return;
        }
        $email = $this->sender->email()
            ->to(new Address((string) $learner->getEmail(), (string) $learner->getDisplayName()))
            ->subject($subject)
            ->htmlTemplate($template.'.html.twig')
            ->textTemplate($template.'.txt.twig')
            ->context(['defense' => $defense, 'track' => $track, 'learner' => $learner]);
        if ($withCalendar) {
            $email->attach($this->calendar->ics($defense, $track), DefenseCalendar::filename($defense), 'text/calendar; charset=utf-8; method=PUBLISH');
        }

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Email de soutenance non envoyé : {message}', ['message' => $e->getMessage(), 'defense' => $defense->getId()]);
        }
    }
}
