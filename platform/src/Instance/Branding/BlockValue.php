<?php

namespace App\Instance\Branding;

/** La règle des valeurs d'un bloc de marque.yaml (couleurs, polices) : rien de ce qu'elle accepte ne peut refermer <style>. */
interface BlockValue
{
    public function accepts(string $value): bool;

    /** Le message d'erreur, pour la clé « bloc.clé ». */
    public function expected(string $path): string;
}
