<?php

namespace App\Content;

use App\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Les exercices de Pratique que l'utilisateur courant peut voir. Un exercice en préparation
 * (`visibility: admin`) ou daté d'un jour à venir (`published`) n'existe que pour les administrateurs ;
 * les cohortes n'y changent rien.
 *
 * Dans la langue de la page : sous /en, seuls les exercices traduits en anglais existent.
 */
final readonly class PracticeVisibility
{
    public function __construct(
        private ContentRepository $content,
        private Security $security,
        private ClockInterface $clock,
        private ?LocaleSwitcher $locale = null,
    ) {
    }

    public function isVisible(Practice $practice): bool
    {
        return (!$practice->isRestricted() && !$this->isScheduled($practice)) || $this->security->isGranted(User::ROLE_ADMIN);
    }

    /** Daté d'un jour à venir, selon l'horloge. */
    public function isScheduled(Practice $practice): bool
    {
        return $practice->isScheduled($this->clock->now());
    }

    /** @return array<string, Practice> du plus récent au plus ancien */
    public function practices(): array
    {
        return array_filter($this->content->practices($this->locale()), $this->isVisible(...));
    }

    public function find(string $id): ?Practice
    {
        $practice = $this->content->findPractice($id, $this->locale());

        return null !== $practice && $this->isVisible($practice) ? $practice : null;
    }

    /** La langue de la page ; une variante régionale (en_GB) compte pour sa langue. */
    public function locale(): string
    {
        return null === $this->locale ? Exercise::LOCALE : explode('_', $this->locale->getLocale())[0];
    }
}
