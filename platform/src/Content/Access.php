<?php

namespace App\Content;

/**
 * Qui peut jouer un exercice. Décidé par le pack de contenu, pas par le moteur.
 */
enum Access: string
{
    /** Gratuit : jouable avec un simple compte, sans acheter le parcours (porte d'entrée d'un parcours). */
    case Free = 'free';
    /** Demande un compte connecté. */
    case Account = 'account';
}
