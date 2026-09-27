<?php

namespace App\Content\Author\Scaffold;

final class SymfonyScaffolder extends ControllerScaffolder
{
    public static function framework(): string
    {
        return 'symfony';
    }

    protected function controllerPath(): string
    {
        return 'src/Controller/MonControleur.php';
    }

    protected function controllerNamespace(): string
    {
        return 'App\\Controller';
    }

    protected function testFolder(): string
    {
        return 'Exercice';
    }

    protected function test(string $dossier): array
    {
        return ["tests/{$dossier}/MonTest.php" => "<?php\n\nnamespace App\\Tests\\{$dossier};\n\nuse Symfony\\Bundle\\FrameworkBundle\\Test\\WebTestCase;\n\nclass MonTest extends WebTestCase\n{\n    public function testLaPageRepond(): void\n    {\n        \$client = static::createClient();\n        \$client->request('GET', '/');\n\n        \$this->assertResponseIsSuccessful();\n    }\n}\n"];
    }
}
