<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell;

/**
 * Analyse un script /bin/sh (le contenu d'un RUN, d'un healthcheck, d'un script d'entrée) :
 * listes séparées par « ; », « && », « || » et retours à la ligne, pipelines, redirections,
 * guillemets, affectations, et les structures if / for / while / case les plus courantes.
 *
 * Représentation (tableaux) :
 *  - script   : list<andor>
 *  - andor    : ['type' => 'andor', 'parts' => list<[op, pipeline]>]   op : '' | '&&' | '||'
 *  - pipeline : ['negate' => bool, 'commands' => list<command>]
 *  - command  : ['type' => 'simple', 'assign' => list<[nom, mot]>, 'words' => list<mot>, 'redirects' => list<[op, mot]>]
 *             | ['type' => 'if', 'clauses' => list<[script, script]>, 'else' => script|null]
 *             | ['type' => 'for', 'var' => string, 'words' => list<mot>|null, 'body' => script]
 *             | ['type' => 'while', 'cond' => script, 'body' => script, 'until' => bool]
 *             | ['type' => 'group', 'body' => script, 'subshell' => bool]
 *             | ['type' => 'case', 'word' => mot, 'items' => list<[list<mot>, script]>]
 *  - mot      : list<[genre, texte]>   genre : 'raw' (sans guillemets) | 'sq' | 'dq'
 */
final class Parser
{
    /** @var list<array{0:string,1:mixed}> jetons : ['word', segments] | ['op', texte] */
    private array $tokens = [];
    private int $position = 0;

    /** @return list<array<string,mixed>> */
    public function parse(string $script): array
    {
        $this->tokens = $this->tokenize($script);
        $this->position = 0;
        $result = $this->parseList([]);
        if ($this->position < \count($this->tokens)) {
            $token = $this->tokens[$this->position];
            throw new SyntaxError(sprintf('syntax error: unexpected "%s"', $token[0] === 'op' ? $token[1] : self::wordText($token[1])));
        }

        return $result;
    }

    /** Texte brut d'un mot (sans expansion), pour les messages et la détection des mots réservés. */
    public static function wordText(array $word): string
    {
        return implode('', array_map(static fn ($s) => $s[1], $word));
    }

    // --- Découpage en jetons --------------------------------------------------------------

    /** @return list<array{0:string,1:mixed}> */
    private function tokenize(string $script): array
    {
        $tokens = [];
        $length = \strlen($script);
        $i = 0;
        $word = [];
        $raw = '';
        $flushRaw = static function () use (&$word, &$raw): void {
            if ($raw !== '') {
                $word[] = ['raw', $raw];
                $raw = '';
            }
        };
        $flushWord = static function () use (&$tokens, &$word, $flushRaw): void {
            $flushRaw();
            if ($word !== []) {
                $tokens[] = ['word', $word];
                $word = [];
            }
        };
        while ($i < $length) {
            $c = $script[$i];
            if ($c === '\\') {
                if ($i + 1 < $length && $script[$i + 1] === "\n") {
                    $i += 2;
                    continue;
                }
                $flushRaw();
                $word[] = ['sq', $script[$i + 1] ?? ''];
                $i += 2;
                continue;
            }
            if ($c === "'") {
                $end = strpos($script, "'", $i + 1);
                if ($end === false) {
                    throw new SyntaxError('syntax error: unterminated quoted string');
                }
                $flushRaw();
                $word[] = ['sq', substr($script, $i + 1, $end - $i - 1)];
                $i = $end + 1;
                continue;
            }
            if ($c === '"') {
                $j = $i + 1;
                $text = '';
                while ($j < $length && $script[$j] !== '"') {
                    if ($script[$j] === '\\' && $j + 1 < $length && \in_array($script[$j + 1], ['"', '\\', '$', '`', "\n"], true)) {
                        $text .= $script[$j + 1] === "\n" ? '' : '\\'.$script[$j + 1];
                        $j += 2;
                        continue;
                    }
                    if ($script[$j] === '$' && ($script[$j + 1] ?? '') === '(') {
                        $end = $this->matchingParen($script, $j + 1);
                        $text .= substr($script, $j, $end - $j + 1);
                        $j = $end + 1;
                        continue;
                    }
                    $text .= $script[$j];
                    ++$j;
                }
                if ($j >= $length) {
                    throw new SyntaxError('syntax error: unterminated quoted string');
                }
                $flushRaw();
                $word[] = ['dq', $text];
                $i = $j + 1;
                continue;
            }
            if ($c === '$' && ($script[$i + 1] ?? '') === '(') {
                $end = $this->matchingParen($script, $i + 1);
                $raw .= substr($script, $i, $end - $i + 1);
                $i = $end + 1;
                continue;
            }
            if ($c === '$' && ($script[$i + 1] ?? '') === '{') {
                $end = strpos($script, '}', $i);
                $end = $end === false ? $length - 1 : $end;
                $raw .= substr($script, $i, $end - $i + 1);
                $i = $end + 1;
                continue;
            }
            if ($c === '`') {
                $end = strpos($script, '`', $i + 1);
                $end = $end === false ? $length - 1 : $end;
                $raw .= '$('.substr($script, $i + 1, $end - $i - 1).')';
                $i = $end + 1;
                continue;
            }
            if ($c === '#' && $word === [] && $raw === '') {
                while ($i < $length && $script[$i] !== "\n") {
                    ++$i;
                }
                continue;
            }
            if ($c === ' ' || $c === "\t" || $c === "\r") {
                $flushWord();
                ++$i;
                continue;
            }
            if ($c === "\n") {
                $flushWord();
                $tokens[] = ['op', "\n"];
                ++$i;
                continue;
            }
            // Redirections avec descripteur : 2>, 2>>, 2>&1, 1>&2, &>
            if (ctype_digit($c) && $word === [] && $raw === '' && preg_match('/^(\d)(>>|>&|>|<)/', substr($script, $i, 4), $m)) {
                $flushWord();
                $tokens[] = ['op', $m[0]];
                $i += \strlen($m[0]);
                continue;
            }
            foreach (['&&', '||', ';;', '&>', '>>', '<<', '>&', ';', '|', '&', '(', ')', '>', '<'] as $op) {
                if (substr($script, $i, \strlen($op)) === $op) {
                    $flushWord();
                    $tokens[] = ['op', $op];
                    $i += \strlen($op);
                    continue 2;
                }
            }
            $raw .= $c;
            ++$i;
        }
        $flushWord();

        return $tokens;
    }

    private function matchingParen(string $script, int $open): int
    {
        $depth = 0;
        $length = \strlen($script);
        for ($k = $open; $k < $length; ++$k) {
            if ($script[$k] === '(') {
                ++$depth;
            } elseif ($script[$k] === ')') {
                --$depth;
                if ($depth === 0) {
                    return $k;
                }
            }
        }

        return $length - 1;
    }

    // --- Grammaire ------------------------------------------------------------------------

    /**
     * @param list<string> $terminators mots réservés qui terminent la liste (then, fi, done…)
     *
     * @return list<array<string,mixed>>
     */
    private function parseList(array $terminators): array
    {
        $list = [];
        while (true) {
            $this->skipSeparators();
            if ($this->atEnd() || $this->atReserved($terminators) || $this->atOp(')') || $this->atOp(';;')) {
                return $list;
            }
            $list[] = $this->parseAndOr();
            if (!$this->atEnd() && ($this->atOp(';') || $this->atOp("\n") || $this->atOp('&'))) {
                ++$this->position;
            }
        }
    }

    /** @return array<string,mixed> */
    private function parseAndOr(): array
    {
        $parts = [['', $this->parsePipeline()]];
        while ($this->atOp('&&') || $this->atOp('||')) {
            $op = $this->tokens[$this->position][1];
            ++$this->position;
            $this->skipNewlines();
            $parts[] = [$op, $this->parsePipeline()];
        }

        return ['type' => 'andor', 'parts' => $parts];
    }

    /** @return array<string,mixed> */
    private function parsePipeline(): array
    {
        $negate = false;
        if ($this->atReserved(['!'])) {
            $negate = true;
            ++$this->position;
        }
        $commands = [$this->parseCommand()];
        while ($this->atOp('|')) {
            ++$this->position;
            $this->skipNewlines();
            $commands[] = $this->parseCommand();
        }

        return ['negate' => $negate, 'commands' => $commands];
    }

    /** @return array<string,mixed> */
    private function parseCommand(): array
    {
        if ($this->atReserved(['if'])) {
            return $this->withRedirects($this->parseIf());
        }
        if ($this->atReserved(['for'])) {
            return $this->withRedirects($this->parseFor());
        }
        if ($this->atReserved(['while', 'until'])) {
            $until = self::wordText($this->tokens[$this->position][1]) === 'until';
            ++$this->position;
            $cond = $this->parseList(['do']);
            $this->expectReserved('do');
            $body = $this->parseList(['done']);
            $this->expectReserved('done');

            return $this->withRedirects(['type' => 'while', 'cond' => $cond, 'body' => $body, 'until' => $until]);
        }
        if ($this->atReserved(['case'])) {
            return $this->withRedirects($this->parseCase());
        }
        if ($this->atReserved(['{'])) {
            ++$this->position;
            $body = $this->parseList(['}']);
            $this->expectReserved('}');

            return $this->withRedirects(['type' => 'group', 'body' => $body, 'subshell' => false]);
        }
        if ($this->atOp('(')) {
            ++$this->position;
            $body = $this->parseList([]);
            if (!$this->atOp(')')) {
                throw new SyntaxError('syntax error: missing ")"');
            }
            ++$this->position;

            return $this->withRedirects(['type' => 'group', 'body' => $body, 'subshell' => true]);
        }

        $assign = [];
        $words = [];
        $redirects = [];
        while (!$this->atEnd()) {
            $token = $this->tokens[$this->position];
            if ($token[0] === 'op') {
                if ($this->isRedirect($token[1])) {
                    ++$this->position;
                    $target = $this->tokens[$this->position] ?? null;
                    if (\in_array($token[1], ['2>&1', '1>&2', '>&'], true) && ($target === null || $target[0] !== 'word' || $token[1] !== '>&')) {
                        $redirects[] = [$token[1], [['raw', '']]];
                        continue;
                    }
                    if ($target === null || $target[0] !== 'word') {
                        throw new SyntaxError(sprintf('syntax error: unexpected redirection "%s"', $token[1]));
                    }
                    ++$this->position;
                    $redirects[] = [$token[1], $target[1]];
                    continue;
                }
                break;
            }
            $text = self::wordText($token[1]);
            if ($words === [] && \count($token[1]) >= 1 && $token[1][0][0] === 'raw' && preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/s', $token[1][0][1], $m)) {
                $value = $token[1];
                $value[0] = ['raw', $m[2]];
                $assign[] = [$m[1], $value];
                ++$this->position;
                continue;
            }
            $words[] = $token[1];
            ++$this->position;
            unset($text);
        }
        if ($words === [] && $assign === [] && $redirects === []) {
            $token = $this->tokens[$this->position] ?? null;
            throw new SyntaxError(sprintf('syntax error: unexpected "%s"', $token === null ? 'end of file' : ($token[0] === 'op' ? trim($token[1]) ?: 'newline' : self::wordText($token[1]))));
        }

        return ['type' => 'simple', 'assign' => $assign, 'words' => $words, 'redirects' => $redirects];
    }

    /** @param array<string,mixed> $command */
    private function withRedirects(array $command): array
    {
        $command['redirects'] = [];
        while (!$this->atEnd() && $this->tokens[$this->position][0] === 'op' && $this->isRedirect($this->tokens[$this->position][1])) {
            $op = $this->tokens[$this->position][1];
            ++$this->position;
            if ($op === '2>&1') {
                continue;
            }
            $target = $this->tokens[$this->position] ?? null;
            if ($target !== null && $target[0] === 'word') {
                ++$this->position;
                $command['redirects'][] = [$op, $target[1]];
            }
        }

        return $command;
    }

    /** @return array<string,mixed> */
    private function parseIf(): array
    {
        ++$this->position; // if
        $clauses = [];
        $else = null;
        $cond = $this->parseList(['then']);
        $this->expectReserved('then');
        $body = $this->parseList(['elif', 'else', 'fi']);
        $clauses[] = [$cond, $body];
        while ($this->atReserved(['elif'])) {
            ++$this->position;
            $cond = $this->parseList(['then']);
            $this->expectReserved('then');
            $clauses[] = [$cond, $this->parseList(['elif', 'else', 'fi'])];
        }
        if ($this->atReserved(['else'])) {
            ++$this->position;
            $else = $this->parseList(['fi']);
        }
        $this->expectReserved('fi');

        return ['type' => 'if', 'clauses' => $clauses, 'else' => $else];
    }

    /** @return array<string,mixed> */
    private function parseFor(): array
    {
        ++$this->position; // for
        $var = self::wordText($this->tokens[$this->position][1] ?? [['raw', '']]);
        ++$this->position;
        $words = null;
        $this->skipNewlines();
        if ($this->atReserved(['in'])) {
            ++$this->position;
            $words = [];
            while (!$this->atEnd() && $this->tokens[$this->position][0] === 'word') {
                $words[] = $this->tokens[$this->position][1];
                ++$this->position;
            }
        }
        $this->skipSeparators();
        $this->expectReserved('do');
        $body = $this->parseList(['done']);
        $this->expectReserved('done');

        return ['type' => 'for', 'var' => $var, 'words' => $words, 'body' => $body];
    }

    /** @return array<string,mixed> */
    private function parseCase(): array
    {
        ++$this->position; // case
        $word = $this->tokens[$this->position][1] ?? [['raw', '']];
        ++$this->position;
        $this->skipNewlines();
        $this->expectReserved('in');
        $items = [];
        while (true) {
            $this->skipSeparators();
            if ($this->atReserved(['esac']) || $this->atEnd()) {
                break;
            }
            if ($this->atOp('(')) {
                ++$this->position;
            }
            $patterns = [];
            while (!$this->atEnd()) {
                $token = $this->tokens[$this->position];
                if ($token[0] === 'word') {
                    $patterns[] = $token[1];
                    ++$this->position;
                } elseif ($token[1] === '|') {
                    ++$this->position;
                } elseif ($token[1] === ')') {
                    ++$this->position;
                    break;
                } else {
                    throw new SyntaxError('syntax error: bad case pattern');
                }
            }
            $body = $this->parseList(['esac']);
            $items[] = [$patterns, $body];
            if ($this->atOp(';;')) {
                ++$this->position;
            }
        }
        $this->expectReserved('esac');

        return ['type' => 'case', 'word' => $word, 'items' => $items];
    }

    // --- Utilitaires ----------------------------------------------------------------------

    private function isRedirect(string $op): bool
    {
        return \in_array($op, ['>', '>>', '<', '<<', '&>', '>&'], true) || preg_match('/^\d(>>|>&|>|<)/', $op) === 1;
    }

    private function atEnd(): bool
    {
        return $this->position >= \count($this->tokens);
    }

    private function atOp(string $op): bool
    {
        $token = $this->tokens[$this->position] ?? null;

        return $token !== null && $token[0] === 'op' && $token[1] === $op;
    }

    /** @param list<string> $words */
    private function atReserved(array $words): bool
    {
        $token = $this->tokens[$this->position] ?? null;

        return $token !== null && $token[0] === 'word' && \count($token[1]) === 1 && $token[1][0][0] === 'raw' && \in_array($token[1][0][1], $words, true);
    }

    private function expectReserved(string $word): void
    {
        $this->skipSeparators();
        if (!$this->atReserved([$word])) {
            $token = $this->tokens[$this->position] ?? null;
            throw new SyntaxError(sprintf('syntax error: unexpected "%s" (expecting "%s")', $token === null ? 'end of file' : ($token[0] === 'op' ? trim($token[1]) ?: 'newline' : self::wordText($token[1])), $word));
        }
        ++$this->position;
    }

    private function skipNewlines(): void
    {
        while ($this->atOp("\n")) {
            ++$this->position;
        }
    }

    private function skipSeparators(): void
    {
        while ($this->atOp("\n") || $this->atOp(';')) {
            ++$this->position;
        }
    }
}
