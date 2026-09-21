<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Compose;

final class ComposeException extends \RuntimeException
{
    /**
     * Les avertissements déjà récoltés quand l'erreur est survenue : Compose les affiche avant
     * l'erreur, même si le fichier ne se charge pas.
     *
     * @var list<string>
     */
    public array $warnings = [];

    /** @param list<string> $warnings */
    public function withWarnings(array $warnings): self
    {
        $this->warnings = $warnings;

        return $this;
    }
}
