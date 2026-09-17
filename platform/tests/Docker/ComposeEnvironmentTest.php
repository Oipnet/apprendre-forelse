<?php

namespace App\Tests\Docker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Yaml;

/**
 * Chaque variable que l'application lit (platform/.env) doit être transmise au conteneur par les fichiers compose
 * de production : oubliée dans l'un d'eux, elle resterait sans effet sur les instances qui l'utilisent, en silence.
 */
final class ComposeEnvironmentTest extends TestCase
{
    /** Posées par l'image elle-même (Dockerfile, docker-entrypoint.sh), pas par l'exploitant. */
    private const array VARIABLES_DE_L_IMAGE = [
        'APP_ENV', 'APP_SHARE_DIR', 'APP_SECRET', 'DEFAULT_URI', 'SANDBOX_ORIGIN', 'ENVIRONMENTS_DIR', 'SESSIONS_DIR',
    ];

    /** @return iterable<string, array{string}> */
    public static function fichiersCompose(): iterable
    {
        yield 'auto-hébergement' => ['auto-hebergement/compose.yaml'];
        yield 'instance de Forelse' => ['deploy/compose.yaml'];
    }

    #[DataProvider('fichiersCompose')]
    public function testToutesLesVariablesDeLApplicationSontTransmises(string $fichier): void
    {
        $racine = \dirname(__DIR__, 3);
        $attendues = array_diff(
            array_keys((new Dotenv())->parse((string) file_get_contents($racine.'/platform/.env'))),
            self::VARIABLES_DE_L_IMAGE,
        );
        $compose = Yaml::parseFile($racine.'/'.$fichier);
        $transmises = array_keys($compose['services']['app']['environment']);

        $this->assertSame([], array_values(array_diff($attendues, $transmises)), sprintf('Variables absentes de %s.', $fichier));
        $this->assertContains('APP_URL', $transmises);
        $this->assertContains('SANDBOX_URL', $transmises);
    }
}
