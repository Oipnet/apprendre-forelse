<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Dockerfile;

/**
 * Analyse un Dockerfile : commentaires, directives (# syntax=, # escape=), continuations de ligne,
 * heredocs (<<EOF), formes shell et exec, drapeaux (--from=, --chown=…), étapes (FROM … AS nom).
 * Les messages d'erreur reprennent ceux de BuildKit, pour que l'apprenant retrouve les mêmes
 * sur sa machine.
 */
final class Parser
{
    private const INSTRUCTIONS = [
        'FROM', 'RUN', 'CMD', 'LABEL', 'MAINTAINER', 'EXPOSE', 'ENV', 'ADD', 'COPY', 'ENTRYPOINT',
        'VOLUME', 'USER', 'WORKDIR', 'ARG', 'ONBUILD', 'STOPSIGNAL', 'HEALTHCHECK', 'SHELL',
    ];

    /** Drapeaux acceptés, par instruction (les autres déclenchent l'erreur de BuildKit). */
    private const FLAGS = [
        'FROM' => ['platform'],
        'RUN' => ['mount', 'network', 'security', 'no-cache-filter'],
        'COPY' => ['from', 'chown', 'chmod', 'link', 'parents', 'exclude'],
        'ADD' => ['chown', 'chmod', 'link', 'checksum', 'keep-git-dir', 'exclude'],
        'HEALTHCHECK' => ['interval', 'timeout', 'start-period', 'start-interval', 'retries'],
    ];

    public function parse(string $content): Dockerfile
    {
        $escape = '\\';
        $lines = preg_split('/\r?\n/', $content) ?: [];
        $logical = $this->logicalLines($lines, $escape);

        $globalArgs = [];
        $stages = [];
        $current = null;
        $seenInstruction = false;
        foreach ($logical as [$text, $lineNumber, $heredoc, $endLine]) {
            if (!$seenInstruction && preg_match('/^#\s*escape\s*=\s*(\S)/i', $text, $m)) {
                $escape = $m[1];
                continue;
            }
            if (str_starts_with(ltrim($text), '#')) {
                continue;
            }
            if (!preg_match('/^\s*(\S+)\s*(.*)$/s', $text, $m)) {
                continue;
            }
            $name = strtoupper($m[1]);
            $rest = trim($m[2]);
            if (!\in_array($name, self::INSTRUCTIONS, true)) {
                throw new ParseError($lineNumber, sprintf('unknown instruction: %s', $m[1]));
            }
            $seenInstruction = true;
            [$flags, $rest] = $this->extractFlags($name, $rest, $lineNumber);
            $exec = $this->execForm($rest);
            if ($rest === '' && $heredoc === null && !\in_array($name, ['CMD', 'ENTRYPOINT'], true)) {
                throw new ParseError($lineNumber, sprintf('%s requires at least one argument', $name));
            }
            $instruction = new Instruction($name, $rest, $flags, $exec, $lineNumber, $text, $heredoc, $endLine);

            if ($name === 'FROM') {
                $this->checkFrom($instruction);
                $current = new Stage(\count($stages), $this->stageName($instruction), $instruction, [$instruction]);
                $stages[] = $current;
                continue;
            }
            if ($current === null) {
                if ($name === 'ARG') {
                    $globalArgs[] = $instruction;
                    continue;
                }
                throw new ParseError($lineNumber, 'no build stage in current context');
            }
            $current->instructions[] = $instruction;
        }
        if (!$stages) {
            throw new ParseError(\count($lines), 'file with no instructions');
        }
        $names = [];
        foreach ($stages as $stage) {
            if ($stage->name !== null) {
                if (isset($names[strtolower($stage->name)])) {
                    throw new ParseError($stage->from->line, sprintf('duplicate name %s', $stage->name));
                }
                $names[strtolower($stage->name)] = true;
            }
        }

        return new Dockerfile($globalArgs, $stages, $escape);
    }

    /**
     * Recolle les continuations de ligne (« \ » en fin de ligne, commentaires intercalés tolérés)
     * et capture les heredocs. Retourne [texte, numéro de la première ligne, heredoc|null].
     *
     * @param list<string> $lines
     *
     * @return list<array{string,int,?string,int}>
     */
    private function logicalLines(array $lines, string $escape): array
    {
        $result = [];
        $count = \count($lines);
        for ($i = 0; $i < $count; ++$i) {
            $line = $lines[$i];
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                if (str_starts_with(ltrim($line), '#')) {
                    $result[] = [$line, $i + 1, null, $i + 1];
                }
                continue;
            }
            $start = $i;
            $text = $line;
            while ($this->endsWithEscape($text, $escape) && $i + 1 < $count) {
                $text = substr(rtrim($text), 0, -1);
                ++$i;
                $next = $lines[$i];
                // Un commentaire (ou une ligne vide) au milieu d'une continuation est ignoré (comportement de BuildKit).
                while ((str_starts_with(ltrim($next), '#') || trim($next) === '') && $i + 1 < $count) {
                    ++$i;
                    $next = $lines[$i];
                }
                $text .= $next;
            }
            $heredoc = null;
            // Heredoc (RUN <<EOF, COPY <<EOF /chemin, RUN cat <<EOF > /fichier) : les lignes suivantes jusqu'au délimiteur.
            if (preg_match('/^\s*(RUN|COPY|ADD)\b.*?(?<!<)<<-?\s*[\'"]?([A-Za-z_][A-Za-z0-9_]*)[\'"]?/i', $text, $m)) {
                $m[1] = $m[2];
                $delimiter = $m[1];
                $body = [];
                for (++$i; $i < $count; ++$i) {
                    if (trim($lines[$i]) === $delimiter) {
                        break;
                    }
                    $body[] = $lines[$i];
                }
                $heredoc = implode("\n", $body)."\n";
            }
            $result[] = [$text, $start + 1, $heredoc, $i + 1];
        }

        return $result;
    }

    private function endsWithEscape(string $text, string $escape): bool
    {
        $trimmed = rtrim($text);

        return $trimmed !== '' && str_ends_with($trimmed, $escape) && !str_ends_with($trimmed, $escape.$escape);
    }

    /** @return array{array<string,string>, string} */
    private function extractFlags(string $name, string $rest, int $line): array
    {
        $flags = [];
        while (preg_match('/^--([a-z-]+)(?:=(\S*))?\s*/', $rest, $m)) {
            $allowed = self::FLAGS[$name] ?? [];
            if (!\in_array($m[1], $allowed, true)) {
                throw new ParseError($line, sprintf('unknown flag: --%s', $m[1]));
            }
            $flags[$m[1]] = $m[2] ?? '';
            $rest = substr($rest, \strlen($m[0]));
        }

        return [$flags, trim($rest)];
    }

    /** @return list<string>|null */
    private function execForm(string $rest): ?array
    {
        if (!str_starts_with($rest, '[')) {
            return null;
        }
        $decoded = json_decode($rest, true);
        if (!\is_array($decoded) || array_is_list($decoded) === false) {
            return null; // « [ ceci n'est pas du JSON ] » : forme shell, comme Docker
        }
        foreach ($decoded as $item) {
            if (!\is_string($item)) {
                return null;
            }
        }

        return array_values($decoded);
    }

    private function checkFrom(Instruction $from): void
    {
        $words = $from->words();
        $count = \count($words);
        if ($count !== 1 && $count !== 3) {
            throw new ParseError($from->line, 'FROM requires either one or three arguments');
        }
        if ($count === 3 && strtoupper($words[1]) !== 'AS') {
            throw new ParseError($from->line, sprintf('FROM requires either one or three arguments'));
        }
    }

    private function stageName(Instruction $from): ?string
    {
        $words = $from->words();

        return \count($words) === 3 ? $words[2] : null;
    }
}
