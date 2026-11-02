<?php

namespace App\Tests\Scratch;

use App\Content\EnvironmentRegistry;
use App\Scratch\ScratchFramework;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La configuration modifiable du bac à sable est copiée de l'environnement dans starter/ : la page sait ainsi la
 * remettre au départ (au lieu de supprimer le fichier) et l'assistant IA la reçoit avec get_starter. La copie doit
 * rester identique à l'environnement, sinon le bac à sable ne démarrerait plus sur la même configuration.
 */
final class ScratchStarterTest extends KernelTestCase
{
    /** Les dossiers de configuration du projet, où un `*` des chemins modifiables peut désigner un fichier existant. */
    private const array CONFIG = ['config', 'config/packages', 'config/routes', 'bootstrap'];

    public function testLaConfigurationModifiableEstCopieeTelleQuelleDansLeDepart(): void
    {
        $environments = static::getContainer()->get(EnvironmentRegistry::class);
        $copied = 0;
        foreach (ScratchFramework::cases() as $framework) {
            $environment = $environments->get($framework->environment());
            $starter = \dirname(__DIR__, 2).'/resources/bac-a-sable/'.$framework->value.'/starter';
            foreach (self::CONFIG as $directory) {
                foreach ($environment->filesIn($directory) as $name => $source) {
                    $path = $directory.'/'.$name;
                    if (!$framework->isEditable($path)) {
                        $this->assertFileDoesNotExist($starter.'/'.$path, "$path n'est pas modifiable : rien à copier.");
                        continue;
                    }
                    $this->assertFileExists($starter.'/'.$path, \sprintf('%s est modifiable dans le bac à sable %s : copiez-le de l\'environnement dans starter/.', $path, $framework->label()));
                    $this->assertFileEquals($source, $starter.'/'.$path, \sprintf('%s a changé dans l\'environnement : recopiez-le dans starter/.', $path));
                    ++$copied;
                }
            }
        }

        $this->assertGreaterThan(10, $copied);
    }

    public function testLesPaquetsInstallesRestentFiges(): void
    {
        $this->assertTrue(ScratchFramework::Symfony->isEditable('config/packages/framework.yaml'));
        $this->assertTrue(ScratchFramework::Symfony->isEditable('config/services.yaml'));
        $this->assertFalse(ScratchFramework::Symfony->isEditable('config/bundles.php'), 'Activer un bundle demande ses paquets dans vendor/.');
        $this->assertFalse(ScratchFramework::Symfony->isEditable('composer.json'));
        $this->assertTrue(ScratchFramework::Laravel->isEditable('config/app.php'));
        $this->assertTrue(ScratchFramework::Laravel->isEditable('bootstrap/app.php'));
        $this->assertFalse(ScratchFramework::Laravel->isEditable('bootstrap/cache/packages.php'));
    }
}
