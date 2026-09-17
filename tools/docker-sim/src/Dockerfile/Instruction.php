<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Dockerfile;

/**
 * Une instruction du Dockerfile, telle qu'écrite (les variables ne sont pas encore développées).
 */
final class Instruction
{
    /**
     * @param string               $name      FROM, RUN, COPY… (en majuscules)
     * @param string               $arguments le reste de la ligne, drapeaux retirés, continuations recollées
     * @param array<string,string> $flags     --from=builder, --chown=www-data, --no-cache-filter… (sans les tirets)
     * @param list<string>|null    $exec      forme exec (JSON) : ["php", "-v"], sinon null (forme shell)
     * @param string|null          $heredoc   contenu d'un heredoc (RUN <<EOF … EOF, COPY <<EOF … EOF)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $arguments,
        public readonly array $flags,
        public readonly ?array $exec,
        public readonly int $line,
        public readonly string $original,
        public readonly ?string $heredoc = null,
        public readonly int $endLine = 0,
    ) {
    }

    /** Arguments découpés sur les espaces (forme shell), ou la forme exec telle quelle. */
    public function words(): array
    {
        if (null !== $this->exec) {
            return $this->exec;
        }

        return preg_split('/\s+/', trim($this->arguments), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Texte de l'instruction sur une ligne, pour l'affichage du build (« RUN apk add … »). */
    public function summary(int $max = 60): string
    {
        $text = preg_replace('/\s+/', ' ', trim($this->original));
        $text = (string) $text;

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
