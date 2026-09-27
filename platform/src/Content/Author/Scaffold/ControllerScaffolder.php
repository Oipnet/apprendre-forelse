<?php

namespace App\Content\Author\Scaffold;

/**
 * Le squelette commun aux frameworks PHP à contrôleurs : un contrôleur à écrire, une page qui doit répondre.
 */
abstract class ControllerScaffolder implements ExerciseScaffolder
{
    public function files(string $entete, string $titre, bool $pratique): array
    {
        $controleur = $this->controllerPath();
        $namespace = $this->controllerNamespace();
        $yaml = $entete.<<<YAML

            open: {$controleur}
            preview: /

            editable:
              - {$controleur}

            objectives:
              - test: testLaPageRepond
                label: La page répond

            hints:
              - Premier indice.

            YAML;

        return [
            'exercise.yaml' => $yaml,
            'instructions.md' => "# {$titre}\n\nÀ écrire.\n",
            'starter/'.$controleur => "<?php\n\nnamespace {$namespace};\n\n// TODO : le code de départ de l'apprenant\n",
            'solution/'.$controleur => "<?php\n\nnamespace {$namespace};\n\n// TODO : la solution\n",
            ...$this->test($pratique ? 'Pratique' : $this->testFolder()),
        ];
    }

    abstract protected function controllerPath(): string;

    abstract protected function controllerNamespace(): string;

    /** Le dossier des tests d'un exercice de parcours, sous tests/ (ceux de Pratique vont dans tests/Pratique). */
    abstract protected function testFolder(): string;

    /**
     * Le test de « la page répond », rangé dans tests/<dossier>/.
     *
     * @return array<string, string>
     */
    abstract protected function test(string $dossier): array;
}
