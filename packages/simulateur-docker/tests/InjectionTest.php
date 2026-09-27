<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Tests;

use Forelse\DockerSim\Catalog\Catalog;
use Forelse\DockerSim\Cli\Application;
use Forelse\DockerSim\Engine\Docker;
use Forelse\DockerSim\Fs\MemoryFs;
use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\State\Store;

final class InjectionTest extends SimulatorTestCase
{
    /** Deux démons dans le même processus : chacun range ses gros contenus dans son propre dossier. */
    public function testDeuxStoresGardentChacunLeursContenus(): void
    {
        $premier = new Store($this->state.'/un');
        $second = new Store($this->state.'/deux');
        $contenu = str_repeat('vendor ', 1_000);

        $fs = new MemoryFs([], [], [], $premier->blobs);
        $fs->write('/app/gros.txt', $contenu);

        $this->assertStringStartsWith('blob:', (string) $fs->blob('/app/gros.txt'));
        $this->assertSame($contenu, $fs->read('/app/gros.txt'));
        $this->assertCount(1, glob($premier->blobs->directory.'/*') ?: []);
        $this->assertSame([], glob($second->blobs->directory.'/*') ?: []);
    }

    /** La CLI travaille sur le démon qu'on lui donne : pas d'aller-retour par state.json. */
    public function testLApplicationPartageLeDemonInjecte(): void
    {
        $shell = Interpreter::create();
        $catalog = new Catalog();
        $docker = new Docker($this->state, $this->project, $shell, $catalog);
        $this->assertSame($shell, $docker->shell);
        $this->assertSame($catalog, $docker->catalog);

        $application = new Application($docker);
        $this->assertSame(0, $application->run(['network', 'create', 'criee']), $application->output);
        $this->assertArrayHasKey('criee', $docker->store->networks);
    }
}
