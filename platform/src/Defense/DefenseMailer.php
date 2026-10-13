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

/** La convocation (avec l'invitation d'agenda) et le résultat d'une soutenance, envoyés à l'apprenant. */
final readonly class DefenseMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private Sender $sender,
        private Theme $theme,
        private DefenseCalendar $calendar,
        private LoggerInterface $logger,
    ) {
    }

    /** N'échoue jamais : le créneau est réservé, et reste affiché sur la page du parcours. */
    public function sendConvocation(Defense $defense, Track $track): void
    {
        $this->send($defense, $track, sprintf('Votre soutenance « %s » le %s', $track->title, $defense->getStartsAt()->format('d/m/Y à H:i')), 'emails/defense_convocation', true);
    }

    /** N'échoue jamais : le résultat est enregistré, et affiché sur la page du parcours. */
    public function sendResult(Defense $defense, Track $track): void
    {
        $this->send($defense, $track, sprintf('Résultat de votre soutenance « %s » sur %s', $track->title, $this->theme->name()), 'emails/defense_result', false);
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
