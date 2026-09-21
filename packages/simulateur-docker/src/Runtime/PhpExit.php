<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Runtime;

/**
 * Un exit() ou un die() du code d'un conteneur, transformé en exception : dans le navigateur, le PHP
 * du conteneur tourne dans le même processus que le simulateur, qu'un vrai exit() arrêterait.
 */
final class PhpExit extends \Exception
{
    public readonly int $status;

    public function __construct(int|string $status = 0)
    {
        // exit("message") affiche le message et sort en 0 ; exit(3) sort en 3.
        if (\is_string($status)) {
            echo $status;
            $status = 0;
        }
        parent::__construct('exit');
        $this->status = $status;
    }

    /**
     * Réécrit exit / die (T_EXIT) en « throw new PhpExit(…) », sans toucher aux chaînes ni aux commentaires.
     * Avec $phpVersion, les constantes de version et de système (PHP_VERSION, PHP_OS…) deviennent celles
     * du conteneur, et non celles du PHP qui fait tourner le simulateur.
     */
    /** @param list<string>|null $extensions extensions chargées dans le conteneur (extension_loaded, get_loaded_extensions, phpversion) */
    public static function rewrite(string $code, ?string $phpVersion = null, ?array $extensions = null): string
    {
        $functions = [];
        if ($extensions !== null) {
            $list = var_export(array_values(array_map('strtolower', $extensions)), true);
            $version = var_export($phpVersion ?? \PHP_VERSION, true);
            $functions = [
                'extension_loaded' => '(static fn (string $__e): bool => \\in_array(strtolower($__e), '.$list.', true))',
                'get_loaded_extensions' => '(static fn (): array => '.var_export(array_values($extensions), true).')',
                'phpversion' => '(static fn (?string $__e = null): string|false => $__e === null || \\in_array(strtolower($__e), '.$list.', true) ? '.$version.' : false)',
            ];
        }
        $constants = [];
        if ($phpVersion !== null) {
            [$major, $minor, $release] = array_map('intval', array_pad(explode('.', $phpVersion), 3, '0'));
            $constants = [
                'PHP_VERSION' => var_export($phpVersion, true),
                'PHP_MAJOR_VERSION' => (string) $major,
                'PHP_MINOR_VERSION' => (string) $minor,
                'PHP_RELEASE_VERSION' => (string) $release,
                'PHP_VERSION_ID' => (string) ($major * 10000 + $minor * 100 + $release),
                'PHP_OS' => "'Linux'",
                'PHP_OS_FAMILY' => "'Linux'",
            ];
        }
        $tokens = token_get_all($code);
        $out = '';
        $count = \count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            $previous = $i > 0 ? $tokens[$i - 1] : null;
            $afterArrow = \is_array($previous) && \in_array($previous[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION], true);
            if (\is_array($token) && \in_array($token[0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true) && !$afterArrow && isset($functions[ltrim(strtolower($token[1]), '\\')])) {
                $out .= $functions[ltrim(strtolower($token[1]), '\\')];
                continue;
            }
            if (\is_array($token) && $token[0] === \T_STRING && isset($constants[$token[1]])) {
                $out .= $constants[$token[1]];
                continue;
            }
            if (!\is_array($token) || $token[0] !== \T_EXIT) {
                $out .= \is_array($token) ? $token[1] : $token;
                continue;
            }
            // Le prochain élément utile : une parenthèse (exit(3)) ou non (exit;).
            $j = $i + 1;
            while ($j < $count && \is_array($tokens[$j]) && $tokens[$j][0] === \T_WHITESPACE) {
                ++$j;
            }
            if ($j < $count && $tokens[$j] === '(') {
                $out .= 'throw new \\'.self::class;
                $i = $j - 1; // la parenthèse et ses arguments sont recopiés tels quels
                // exit() sans argument : « new PhpExit() » convient aussi.
                continue;
            }
            $out .= '(throw new \\'.self::class.'(0))';
        }

        return $out;
    }
}
