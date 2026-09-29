<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * La règle d'un nouveau mot de passe, la même partout : inscription, changement depuis le compte, réinitialisation.
 *
 * Pas de règles de composition (« une majuscule, un chiffre… »), que l'ANSSI et le NIST déconseillent : elles
 * produisent des « Motdepasse1! » prévisibles. On mesure plutôt l'entropie (PasswordStrength), et on refuse les mots
 * de passe publiés dans une fuite (NotCompromisedPassword : seuls les 5 premiers caractères de l'empreinte SHA-1
 * partent vers Have I Been Pwned). Si ce service ne répond pas, le mot de passe passe : une panne chez lui ne doit pas
 * bloquer les inscriptions. PASSWORD_BREACH_CHECK=0 coupe cette vérification (instance sans accès à l'internet).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class StrongPassword extends Compound
{
    /** @param array<string, mixed> $options */
    protected function getConstraints(array $options): array
    {
        // L'une après l'autre : un seul message à la fois, et Have I Been Pwned n'est interrogé que pour un mot de
        // passe qui a passé les autres règles.
        return [new Assert\Sequentially([
            new Assert\NotBlank(message: 'Choisissez un mot de passe.'),
            new Assert\Length(min: 8, max: 4096, minMessage: 'Au moins {{ limit }} caractères.'),
            new Assert\PasswordStrength(
                minScore: Assert\PasswordStrength::STRENGTH_MEDIUM,
                message: 'Ce mot de passe est trop facile à deviner. Le plus simple : une phrase de plusieurs mots (« la-mouette-rit-au-port »), plus longue plutôt que plus compliquée.',
            ),
            new Assert\NotCompromisedPassword(
                message: 'Ce mot de passe figure dans une fuite de données connue : il fait partie des premiers essayés par les pirates. Choisissez-en un autre.',
                skipOnError: true,
            ),
        ])];
    }
}
