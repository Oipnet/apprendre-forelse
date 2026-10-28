<?php

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\Token;

/**
 * Internationalisation : les gabarits du moteur n'écrivent aucun texte en dur, tout passe par |trans (translations/).
 * Repère le texte visible hors des balises Twig, les attributs lus par un humain (alt, title, aria-label, placeholder…)
 * et les chaînes Twig qui ressemblent à une phrase. Les commentaires, <script> et <style> sont ignorés.
 */
final class HardcodedTextTest extends TestCase
{
    /**
     * Gabarits dont le texte reste dans le gabarit, volontairement (préfixes de chemin) :
     * - les pages juridiques, un texte que l'on fait relire tel quel : elles auront un gabarit par langue ;
     * - l'administration, l'atelier des auteurs, l'espace et l'offre des organisations (cohortes) : outils internes ou
     *   clientèle française, servis en français seulement ;
     * - les e-mails internes, envoyés à l'administrateur de l'instance.
     */
    private const EXCEPTIONS = [
        'legal/',
        'admin/',
        'studio/',
        'cohorte/',
        'contact/organizations.html.twig',
        'contact/free_class.html.twig',
        '_theme_preview.html.twig',
        'emails/registration_alert.txt.twig',
        'emails/contact_message.txt.twig',
        'emails/free_class_alert.txt.twig',
    ];

    /** Attributs qu'un humain lit ou entend : leur valeur se traduit. */
    private const READ_ATTRIBUTES = '(?:alt|title|aria-label|aria-description|aria-roledescription|placeholder|label|data-confirm|data-[a-z-]*-label|data-[a-z-]*-text|data-[a-z-]*-message)';

    public function testLesGabaritsNEcriventAucunTexteEnDur(): void
    {
        $dir = \dirname(__DIR__, 2).'/templates';
        $twig = new Environment(new ArrayLoader());
        $found = [];
        foreach ((new Finder())->files()->in($dir)->name('*.twig')->sortByName() as $file) {
            $name = str_replace('\\', '/', $file->getRelativePathname());
            foreach (self::EXCEPTIONS as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    continue 2;
                }
            }
            foreach (self::texts($twig, $file->getContents(), $name) as [$line, $text]) {
                $found[] = sprintf('%s:%d « %s »', $name, $line, mb_strimwidth($text, 0, 80, '…'));
            }
        }

        $this->assertSame([], $found, "Texte en dur dans les gabarits : passez-le par |trans (voir translations/README.md).\n".implode("\n", $found));
    }

    /** @param list<string> $expected */
    #[DataProvider('provideTexts')]
    public function testLeReperageSAttacheAuTexteVisible(string $template, array $expected): void
    {
        $texts = array_map(static fn (array $t) => $t[1], self::texts(new Environment(new ArrayLoader()), $template, 'x.twig'));

        $this->assertSame($expected, $texts);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function provideTexts(): iterable
    {
        yield 'texte entre balises' => ['<p class="lead">Bonjour {{ nom }}</p>', ['Bonjour']];
        yield 'clé traduite' => ['<p class="lead">{{ \'accueil.titre\'|trans }}</p>', []];
        yield 'ponctuation, entités, nombres' => ['<span>·</span> &nbsp; <b>42</b> → {{ x }} XP', ['XP']];
        yield 'attribut lu' => ['<img alt="Logo du site" src="/a.png"><a title="{{ t }}" class="btn btn-primary">Lien</a>', ['Logo du site', 'Lien']];
        yield 'script et style ignorés' => ['<script>const a = "Bonjour {{ x }} le monde";</script><style>.a { color: red }</style>', []];
        yield 'commentaire html ignoré' => ['<!-- Un commentaire --><p>{{ a }}</p>', []];
        yield 'phrase dans une chaîne Twig' => ["{{ ok ? 'réussi le ' ~ d : 'en cours' }}", ['réussi le ']];
        yield 'identifiants Twig' => ["{{ path('app_home', {suite: 'x'}) }}{{ d|date('d/m/Y') }}{% if a == 'github' %}{% endif %}", []];
    }

    /**
     * Les textes visibles d'un gabarit, avec leur ligne.
     *
     * @return list<array{int, string}>
     */
    private static function texts(Environment $twig, string $code, string $name): array
    {
        $stream = $twig->tokenize(new Source($code, $name));
        $texts = [];
        // Le HTML seul, chaque balise Twig remplacée par un séparateur ; et la ligne où commence chaque morceau.
        $html = '';
        $lines = [];
        while (!$stream->isEOF()) {
            $token = $stream->next();
            if ($token->test(Token::TEXT_TYPE)) {
                $lines[] = [\strlen($html), $token->getLine()];
                $html .= $token->getValue();
            } elseif ($token->test(Token::VAR_START_TYPE) || $token->test(Token::BLOCK_START_TYPE)) {
                $html .= "\u{1}";
            } elseif ($token->test(Token::STRING_TYPE) && self::looksLikeProse($token->getValue())
                && !$stream->getCurrent()->test(Token::PUNCTUATION_TYPE, '|') // une clé : 'x'|trans
            ) {
                $texts[] = [$token->getLine(), $token->getValue()];
            }
        }
        $lineAt = static function (int $offset) use ($lines): int {
            $line = 1;
            foreach ($lines as [$start, $l]) {
                if ($start > $offset) {
                    break;
                }
                $line = $l;
            }

            return $line;
        };
        // Chaque remplacement garde la longueur : les positions, donc les lignes, restent justes.
        $blank = static fn (array $m): string => str_repeat("\u{1}", \strlen($m[0]));

        // Commentaires HTML, <script>, <style> : rien à lire.
        $html = (string) preg_replace_callback('#<!--.*?-->|<(script|style)\b.*?</\1\s*>#si', $blank, $html);

        // Attributs lus par un humain.
        preg_match_all('#\s'.self::READ_ATTRIBUTES.'\s*=\s*(?:"([^"]*)"|\'([^\']*)\')#i', $html, $attrs, \PREG_OFFSET_CAPTURE | \PREG_SET_ORDER);
        foreach ($attrs as $attr) {
            $value = '' !== ($attr[2][0] ?? '') ? $attr[2][0] : $attr[1][0];
            if (self::readable($value)) {
                $texts[] = [$lineAt($attr[0][1]), self::clean($value)];
            }
        }

        // Le texte hors des balises HTML.
        $html = (string) preg_replace_callback('#<[^>]*>#s', $blank, $html);
        foreach (preg_split("#\u{1}+#", $html, -1, \PREG_SPLIT_NO_EMPTY | \PREG_SPLIT_OFFSET_CAPTURE) ?: [] as [$chunk, $offset]) {
            if (self::readable($chunk)) {
                $texts[] = [$lineAt($offset + \strlen($chunk) - \strlen(ltrim($chunk))), self::clean($chunk)];
            }
        }

        usort($texts, static fn (array $a, array $b) => $a[0] <=> $b[0]);

        return $texts;
    }

    private static function readable(string $text): bool
    {
        return 1 === preg_match('/\p{L}{2,}/u', html_entity_decode(str_replace("\u{1}", ' ', $text)));
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(str_replace("\u{1}", ' ', $text))));
    }

    /** Une chaîne Twig qui se lit : des mots séparés d'espaces, ou une lettre accentuée. Pas une classe CSS ni un format. */
    private static function looksLikeProse(string $value): bool
    {
        if (preg_match('/[À-ÿŒœ]/u', $value)) {
            return true;
        }

        return 1 === preg_match('/^\p{Lu}\p{Ll}+(?: \p{Ll}+)+/u', $value) || 1 === preg_match('/^\p{Ll}+ (?:\p{Ll}+ )*\p{Ll}+ ?$/u', $value) && !preg_match('/^[a-z0-9-:\/\[\]]+(?: [a-z0-9-:\/\[\]]+)*$/', $value);
    }
}
