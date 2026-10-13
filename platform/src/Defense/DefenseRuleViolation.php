<?php

namespace App\Defense;

use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Une règle des soutenances refuse l'opération : rien n'a été enregistré. Le message s'affiche tel quel.
 * Celles que voit l'apprenant portent aussi une clé du domaine apprentissage : le message, en français, reste pour les
 * journaux et pour l'espace des cohortes (servi en français seulement).
 */
final class DefenseRuleViolation extends \DomainException implements TranslatableInterface
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        string $message,
        private readonly ?string $key = null,
        private readonly array $parameters = [],
    ) {
        parent::__construct($message);
    }

    /** Ce qu'un message flash peut garder en session (l'exception elle-même ne se sérialise pas). */
    public function flash(): TranslatableMessage|string
    {
        return null === $this->key ? $this->getMessage() : new TranslatableMessage($this->key, $this->parameters, 'apprentissage');
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return null === $this->key ? $this->getMessage() : $translator->trans($this->key, $this->parameters, 'apprentissage', $locale);
    }
}
