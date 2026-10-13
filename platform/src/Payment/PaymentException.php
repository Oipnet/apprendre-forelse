<?php

namespace App\Payment;

use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le prestataire de paiement a refusé ou n'a pas répondu, ou le paiement n'est pas configuré. Message lisible par
 * l'apprenant : getMessage() en français, pour les journaux ; traduit (trans(), userMessage()) quand celui qui la lève
 * en donne la clé, et sinon le même message.
 */
final class PaymentException extends \RuntimeException implements TranslatableInterface
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly ?TranslatableMessage $translatable = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** Le message pour l'apprenant, à traduire plus tard (un message flash, par exemple). */
    public function userMessage(): TranslatableMessage
    {
        return $this->translatable ?? new TranslatableMessage($this->getMessage());
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->userMessage()->trans($translator, $locale);
    }
}
