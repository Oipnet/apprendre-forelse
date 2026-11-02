<?php

namespace App\Content;

use App\Scratch\ScratchFramework;

/**
 * « Essayer ce code » : un bloc de code d'article qui s'ouvre dans le bac à sable. L'auteur l'annonce dans
 * l'en-tête du bloc, après le langage :
 *
 *     ```php sandbox=php                      → public/index.php du bac à sable PHP (son fichier ouvert à l'arrivée)
 *     ```php sandbox=symfony:src/Controller/MenuController.php
 *
 * Le bloc devient tout le fichier : il doit se suffire (namespace, use…). Le chemin suit les règles du bac à sable
 * (ScratchFramework::editable) : content:check refuse un framework ou un chemin qu'il n'accepte pas.
 */
final readonly class CodeSandbox
{
    public const string PREFIX = 'sandbox=';

    private function __construct(public ScratchFramework $framework, public string $path)
    {
    }

    /** Le mot de l'en-tête qui annonce le bac à sable, s'il y en a un. */
    public static function wordOf(string $info): ?string
    {
        foreach (preg_split('/\s+/', trim($info)) ?: [] as $word) {
            if (str_starts_with($word, self::PREFIX)) {
                return $word;
            }
        }

        return null;
    }

    /** Le bac à sable et le fichier qu'annonce ce mot ; null s'il est invalide (voir problem). */
    public static function of(string $word): ?self
    {
        return null === self::problem($word) ? self::parse($word) : null;
    }

    /** Ce qui ne va pas dans ce mot, en clair ; null s'il est valide. */
    public static function problem(string $word): ?string
    {
        $target = substr($word, \strlen(self::PREFIX));
        [$framework, $path] = array_pad(explode(':', $target, 2), 2, null);
        $known = ScratchFramework::tryFrom((string) $framework);
        if (null === $known) {
            return \sprintf('« %s » : bac à sable inconnu (%s).', $word, implode(', ', array_map(static fn (ScratchFramework $f) => $f->value, ScratchFramework::cases())));
        }
        if (null !== $path && !$known->isEditable($path)) {
            return \sprintf('« %s » : le bac à sable %s ne modifie pas ce fichier (%s).', $word, $known->label(), implode(', ', $known->editable()));
        }

        return null;
    }

    /**
     * Les mots « sandbox=… » invalides des blocs de code d'un Markdown, avec leur problème.
     *
     * @return list<string>
     */
    public static function problemsIn(string $markdown): array
    {
        preg_match_all('/^ {0,3}(?:`{3,}|~{3,})([^\n`]*)$/m', $markdown, $fences);
        $problems = [];
        foreach ($fences[1] as $info) {
            $word = self::wordOf($info);
            if (null !== $word && null !== ($problem = self::problem($word))) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    private static function parse(string $word): self
    {
        [$framework, $path] = array_pad(explode(':', substr($word, \strlen(self::PREFIX)), 2), 2, null);
        $framework = ScratchFramework::from((string) $framework);

        return new self($framework, $path ?? $framework->open());
    }
}
