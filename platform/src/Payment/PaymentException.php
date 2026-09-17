<?php

namespace App\Payment;

/** Le prestataire de paiement a refusé ou n'a pas répondu, ou le paiement n'est pas configuré. Message lisible par l'apprenant. */
final class PaymentException extends \RuntimeException
{
}
