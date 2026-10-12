<?php

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Vérification serveur d'une réussite (voir App\Verification) : le code soumis rejoué contre les tests de l'exercice,
 * dans le service « verificateur ». Traduisible : EasyAdmin affiche label() tel quel.
 */
enum VerificationStatus: string implements TranslatableInterface
{
    use EnumLabels;
    use EnumBadges;

    /** En file : pas encore rejouée. La réussite compte au classement en attendant. */
    case Pending = 'pending';
    /** Les tests passent aussi sur le serveur. */
    case Passed = 'passed';
    /** Les tests échouent sur le serveur : l'XP de l'exercice sort du classement. */
    case Failed = 'failed';
    /** Impossible à rejouer ici (lanceur absent, exercice retiré, réussite sans code) : on fait confiance. */
    case Unverifiable = 'unverifiable';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Passed => 'Confirmée',
            self::Failed => 'Infirmée',
            self::Unverifiable => 'Non vérifiable',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'secondary',
            self::Passed => 'success',
            self::Failed => 'danger',
            self::Unverifiable => 'light',
        };
    }
}
