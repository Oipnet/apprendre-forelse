<?php

namespace App\Content\Check;

use App\Content\Exercise;
use App\Instance\EnvironmentArtifacts;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Les classes que l'apprenant doit importer lui-même — citées par la solution, absentes de l'état de départ —
 * doivent figurer dans l'index de complétion de l'environnement. Sinon l'éditeur ne les propose pas, alors que
 * l'exercice demande précisément de les écrire. La liste des namespaces indexés est dans
 * environments/bin/build-completion.php ; l'index lui-même est produit par build-env.sh.
 */
#[AsTaggedItem(priority: 10)]
final readonly class CompletionIndexCheck implements ExerciseCheck
{
    public function __construct(
        /** Où vivent les index : le public/ du moteur, ou les environnements installés. */
        private EnvironmentArtifacts $artifacts,
    ) {
    }

    public function check(Exercise $exercise, CheckContext $context, CheckResult $result): void
    {
        $environment = $context->environment;
        $indexPath = $this->artifacts->path(EnvironmentArtifacts::completionName($environment->id));
        if (null === $indexPath) {
            $result->warning(sprintf('Index de complétion absent (%s) : les imports de la solution n\'ont pas été vérifiés. Lancez environments/bin/build-env.sh %s.', $environment->completionIndexPath(), $environment->id));

            return;
        }
        $known = json_decode((string) file_get_contents($indexPath), true)['classes'] ?? [];
        // Environnement sans PHP (Nuxt) : son index est vide, il n'y a pas d'import à vérifier.
        if (!$known) {
            return;
        }

        // Une classe que l'exercice fournit lui-même (entité, factory, test, y compris héritée d'une base)
        // n'a rien à faire dans l'index : elle n'existe que le temps de l'exercice.
        $provided = [];
        foreach ([...$context->starting, ...$context->tests, ...$context->solution] as $path => $code) {
            foreach (self::declaredClasses($path, $code) as $class) {
                $provided[$class] = true;
            }
        }
        // Ce que l'état de départ importe déjà est sous les yeux de l'apprenant : il n'a pas à le retrouver.
        $already = [];
        foreach ($context->starting as $path => $code) {
            foreach (self::importedClasses($path, $code) as $class) {
                $already[$class] = true;
            }
        }

        $missing = [];
        foreach ($context->solution as $path => $code) {
            foreach (self::importedClasses($path, $code) as $class) {
                if (!isset($already[$class]) && !isset($provided[$class]) && !isset($known[$class])) {
                    $missing[$class] = true;
                }
            }
        }
        if ($missing) {
            $result->error(sprintf(
                'Complétion : %s hors de l\'index de « %s ». L\'apprenant doit écrire ces imports, l\'éditeur ne les lui proposera pas : ajoutez leur namespace à environments/bin/build-completion.php.',
                implode(', ', array_keys($missing)),
                $environment->id,
            ));
        }
    }

    /**
     * Imports d'un fichier PHP, en pleine qualification. Le `use` d'un trait est indenté dans le corps de la
     * classe, celui d'un import commence la ligne : la distinction tient à cette colonne.
     *
     * @return list<string>
     */
    public static function importedClasses(string $path, string $code): array
    {
        if (!str_ends_with($path, '.php')) {
            return [];
        }
        preg_match_all('/^use\s+(?!function\s|const\s)([A-Z][\w\\\\]*\\\\[\w\\\\]+?)(?:\s+as\s+\w+)?\s*;/m', $code, $matches);

        return $matches[1];
    }

    /**
     * Classes, interfaces, traits et énumérations qu'un fichier PHP déclare, en pleine qualification.
     *
     * @return list<string>
     */
    public static function declaredClasses(string $path, string $code): array
    {
        if (!str_ends_with($path, '.php')) {
            return [];
        }
        $namespace = preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $code, $found) ? $found[1].'\\' : '';
        preg_match_all('/^(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $code, $matches);

        return array_map(static fn (string $name) => $namespace.$name, $matches[1]);
    }
}
