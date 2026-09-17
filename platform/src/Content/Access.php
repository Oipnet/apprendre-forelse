<?php

namespace App\Content;

/**
 * Qui peut jouer un exercice. Décidé par le pack de contenu, pas par le moteur.
 */
enum Access: string
{
    /** Jouable sans compte (porte d'entrée d'un parcours). */
    case Free = 'free';
    /** Demande un compte connecté. */
    case Account = 'account';
}
