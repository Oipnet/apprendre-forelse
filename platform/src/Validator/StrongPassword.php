<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * La règle d'un nouveau mot de passe, la même à l'inscription, depuis le compte et à la réinitialisation.
 *
 * Une entropie minimale plutôt que des règles de composition (« une majuscule, un chiffre… »), que l'ANSSI et le NIST
 * déconseillent : « azertyuiop » ou « motdepasse » sont refusés, une phrase de plusieurs mots passe. Puis un mot de
 * passe publié dans une fuite connue est refusé (Have I Been Pwned : seuls les 5 premiers caractères de son empreinte
 * SHA-1 partent). Si ce service ne répond pas, on laisse passer : sa panne ne bloque pas les inscriptions. Une
 * instance sans accès sortant le coupe avec PASSWORD_BREACH_CHECK=0.
 *
 * Une règle à la fois (Sequentially) : le premier défaut, avec de quoi le corriger, plutôt qu'une liste.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class StrongPassword extends Compound
{
    /** Les messages, des clés du domaine validators (translations/validators.fr.yaml). */
    public const string TOO_WEAK = 'mot_de_passe.trop_faible';
    public const string COMPROMISED = 'mot_de_passe.compromis';

    /** @param array<string, mixed> $options */
    protected function getConstraints(array $options): array
    {
        return [new Assert\Sequentially([
            new Assert\NotBlank(message: 'mot_de_passe.vide'),
            new Assert\Length(min: 8, max: 4096, minMessage: 'mot_de_passe.trop_court'),
            new Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM, message: self::TOO_WEAK),
            new Assert\NotCompromisedPassword(message: self::COMPROMISED, skipOnError: true),
        ])];
    }
}
