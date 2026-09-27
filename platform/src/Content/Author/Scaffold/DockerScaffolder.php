<?php

namespace App\Content\Author\Scaffold;

/** Un Dockerfile à écrire, une image qui doit se construire. Les tests restent dans tests/Docker/, Pratique comprise. */
final class DockerScaffolder implements ExerciseScaffolder
{
    public static function framework(): string
    {
        return 'docker';
    }

    public function files(string $entete, string $titre, bool $pratique): array
    {
        return [
            'exercise.yaml' => $entete."\nopen: Dockerfile\npreview: /localhost:8080/\n\neditable:\n  - Dockerfile\n\nobjectives:\n  - test: testLImageSeConstruit\n    label: L'image se construit\n\nhints:\n  - Premier indice.\n",
            'instructions.md' => "# {$titre}\n\nÀ écrire.\n",
            'starter/Dockerfile' => "# TODO : le Dockerfile de départ de l'apprenant\n",
            'solution/Dockerfile' => "FROM php:8.4-apache\nCOPY public/ /var/www/html/\n",
            'tests/Docker/MonTest.php' => "<?php\n\nnamespace Tests\\Docker;\n\nuse Forelse\\DockerSim\\Testing\\DockerTestCase;\n\nclass MonTest extends DockerTestCase\n{\n    public function testLImageSeConstruit(): void\n    {\n        \$this->assertBuildSucceeded(\$this->build('app'));\n    }\n}\n",
        ];
    }
}
