<?php

namespace App\Tests\Content\Check;

use App\Content\Check\ProcessEnvironment;
use PHPUnit\Framework\TestCase;

final class ProcessEnvironmentTest extends TestCase
{
    /** En production, DATABASE_URL est une vraie variable d'environnement, pas une valeur du .env : elle doit être cachée aussi. */
    public function testLesVariablesDeLaPlateformeSontCacheesAuProjetTeste(): void
    {
        $env = (new ProcessEnvironment(__DIR__.'/../../..'))->isolated(['SYMFONY_DOTENV_VARS' => 'INJECTEE,APP_ENV,APP_DEBUG']);

        $this->assertSame('test', $env['APP_ENV']);
        $this->assertSame('0', $env['APP_DEBUG'], 'Sans debug : la page d\'erreur citerait le code du test.');
        $this->assertFalse($env['DATABASE_URL']);
        $this->assertFalse($env['SANDBOX_ORIGIN'], 'Déclarée dans platform/.env, même si Dotenv ne l\'a pas injectée.');
        $this->assertFalse($env['ANTHROPIC_API_KEY']);
        $this->assertFalse($env['INJECTEE'], 'Injectée par le Dotenv de la plateforme.');
    }

    public function testLesTestsTournentSousLeurPropreUtilisateur(): void
    {
        $this->assertSame(['php', 'x'], (new ProcessEnvironment())->command(['php', 'x']), 'Sans VERIFICATION_RUNNER_UID, rien ne change.');

        $command = (new ProcessEnvironment(runnerUid: 65533))->command(['php', 'x']);

        $this->assertSame('setpriv', $command[0]);
        $this->assertContains('--reuid=65533', $command);
        $this->assertContains('--ambient-caps=-all', $command, 'Le droit de changer d\'utilisateur ne passe pas aux tests.');
        $this->assertSame(['php', 'x'], \array_slice($command, -2));
    }
}
