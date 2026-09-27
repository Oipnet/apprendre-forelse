<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Cli;

/**
 * Ce qu'une commande docker affiche (stdout et stderr mêlés, dans l'ordre). Le texte s'ajoute au fil
 * de l'eau à la chaîne reçue (Application::$output) : un exit() de PHP en plein conteneur ne le perd pas.
 */
final class Output
{
    private string $text;

    public function __construct(string &$buffer)
    {
        $this->text = &$buffer;
    }

    public function write(string $text): void
    {
        $this->text .= $text;
    }

    public function line(string $text = ''): void
    {
        $this->text .= $text."\n";
    }
}
