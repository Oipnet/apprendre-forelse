<?php

namespace App\Tests\Content\Check;

use App\Content\Check\ProcessEnvironment;
use PHPUnit\Framework\TestCase;

final class ProcessEnvironmentTest extends TestCase
{
    /** En production, DATABASE_URL est une vraie variable d'environnement, pas une valeur du .env : elle doit être cachée aussi. */
    public function testLesVariablesDeLaPlateformeSontCacheesAuProjetTeste(): void
    {
        $env = (new ProcessEnvironment(__DIR__.'/../../..'))->isolated(['SYMFONY_DOTENV_VARS' => 'INJECTEE,APP_ENV']);

        $this->assertSame('test', $env['APP_ENV']);
        $this->assertFalse($env['DATABASE_URL']);
        $this->assertFalse($env['SANDBOX_ORIGIN'], 'Déclarée dans platform/.env, même si Dotenv ne l\'a pas injectée.');
        $this->assertFalse($env['ANTHROPIC_API_KEY']);
        $this->assertFalse($env['INJECTEE'], 'Injectée par le Dotenv de la plateforme.');
    }
}
