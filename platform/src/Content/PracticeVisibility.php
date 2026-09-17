<?php

namespace App\Content;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Les exercices de Pratique que l'utilisateur courant peut voir. Un exercice en préparation
 * (`visibility: admin`) ou daté d'un jour à venir (`published`) n'existe que pour les administrateurs ;
 * les cohortes n'y changent rien.
 */
final readonly class PracticeVisibility
{
    public function __construct(
        private ContentRepository $content,
        private Security $security,
    ) {
    }

    public function isVisible(Practice $practice): bool
    {
        return (!$practice->isRestricted() && !$practice->isScheduled()) || $this->security->isGranted(User::ROLE_ADMIN);
    }

    /** @return array<string, Practice> du plus récent au plus ancien */
    public function practices(): array
    {
        return array_filter($this->content->practices(), $this->isVisible(...));
    }

    public function find(string $id): ?Practice
    {
        $practice = $this->content->findPractice($id);

        return null !== $practice && $this->isVisible($practice) ? $practice : null;
    }
}
