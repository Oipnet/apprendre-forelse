<?php

namespace App\Content\Author\Scaffold;

final class LaravelScaffolder extends ControllerScaffolder
{
    public static function framework(): string
    {
        return 'laravel';
    }

    protected function controllerPath(): string
    {
        return 'app/Http/Controllers/MonControleur.php';
    }

    protected function controllerNamespace(): string
    {
        return 'App\\Http\\Controllers';
    }

    protected function testFolder(): string
    {
        return 'Formation';
    }

    protected function test(string $dossier): array
    {
        return ["tests/{$dossier}/MonTest.php" => "<?php\n\nnamespace Tests\\{$dossier};\n\nuse Tests\\TestCase;\n\nclass MonTest extends TestCase\n{\n    public function testLaPageRepond(): void\n    {\n        \$this->get('/')->assertOk();\n    }\n}\n"];
    }
}
