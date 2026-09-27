<?php

namespace App\Entity;

/** Ce que l'administration lit puis marque « traité » : un retour d'apprenant, un message de contact. */
interface Handleable
{
    public function isHandled(): bool;

    public function setHandled(bool $handled): static;
}
