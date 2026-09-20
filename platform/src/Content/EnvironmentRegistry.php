<?php

namespace App\Content;

use App\Content\Framework\FrameworkRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final class EnvironmentRegistry
{
    /** @var array<string, Environment> */
    private array $environments = [];

    private readonly FrameworkRegistry $frameworks;

    public function __construct(
        #[Autowire(env: 'resolve:ENVIRONMENTS_DIR')]
        private readonly string $directory,
        ?FrameworkRegistry $frameworks = null,
    ) {
        $this->frameworks = $frameworks ?? new FrameworkRegistry();
    }

    public function has(string $id): bool
    {
        return is_file($this->directory.'/'.$id.'/environment.yaml');
    }

    public function get(string $id): Environment
    {
        if (isset($this->environments[$id])) {
            return $this->environments[$id];
        }
        if (!$this->has($id)) {
            throw new ContentException(sprintf('Environnement « %s » introuvable dans %s.', $id, $this->directory));
        }

        $directory = $this->directory.'/'.$id;
        $meta = Yaml::parseFile($directory.'/environment.yaml');
        $name = (string) ($meta['framework'] ?? FrameworkRegistry::DEFAULT);
        if (!$this->frameworks->has($name)) {
            throw new ContentException(sprintf('Environnement « %s » : framework « %s » inconnu (%s).', $id, $name, implode(', ', $this->frameworks->ids())));
        }
        $framework = $this->frameworks->get($name);

        return $this->environments[$id] = new Environment(
            id: $id,
            title: $meta['title'] ?? $id,
            // Un environnement sans PHP (Nuxt, joué par le simulateur du navigateur) n'a pas de version à déclarer.
            phpVersion: (string) ($meta['php'] ?? ($framework->runsPhpunit() ? throw new ContentException(sprintf('Environnement « %s » : clé « php » manquante.', $id)) : '')),
            directory: $directory,
            framework: $framework,
            // Symfony compile son container dans var/cache, Laravel ses vues Blade : le profil le sait,
            // et « cache: » dans environment.yaml a le dernier mot pour un projet arrangé autrement.
            cacheDirs: array_values(array_map('strval', (array) ($meta['cache'] ?? $framework->cacheDirs))),
        );
    }
}
