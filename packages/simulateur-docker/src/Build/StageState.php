<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Build;

use Forelse\DockerSim\Fs\MemoryFs;
use Forelse\DockerSim\Shell\Facts;
use Forelse\DockerSim\State\ImageConfig;
use Forelse\DockerSim\State\Layer;

/**
 * L'état d'une étape en cours de construction : système de fichiers, faits de l'image (paquets,
 * extensions…), configuration, couches déjà produites et clé de cache de la dernière. Une étape
 * qui part d'une autre (FROM builder) en reçoit une copie profonde : elle ne modifie pas l'originale.
 */
final class StageState
{
    /** @param list<Layer> $layers */
    public function __construct(
        public MemoryFs $fs,
        public Facts $facts,
        public ImageConfig $config,
        public array $layers,
        public string $key,
        public readonly string $base,
        public readonly string $kind,
        public readonly string $os,
        public readonly ?string $phpVersion,
        public readonly ?string $docroot,
    ) {
    }

    public function __clone()
    {
        $this->fs = $this->fs->copyFiles();
        $this->facts = clone $this->facts;
        $this->config = clone $this->config;
    }
}
