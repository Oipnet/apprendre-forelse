<?php

namespace App\Security;

use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Trop de demandes en peu de temps (messages, avis…) : rien n'a été enregistré. Le message s'adresse à l'utilisateur :
 * getMessage() en français, pour les journaux ; traduit (trans()) quand celui qui la lève en donne la clé, et sinon le
 * même message.
 */
final class QuotaExceeded extends \RuntimeException implements TranslatableInterface
{
    public function __construct(string $message = '', private readonly ?TranslatableMessage $translatable = null)
    {
        parent::__construct($message);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return ($this->translatable ?? new TranslatableMessage($this->getMessage()))->trans($translator, $locale);
    }
}
