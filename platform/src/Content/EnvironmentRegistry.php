<?php

namespace App\Content;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final class EnvironmentRegistry
{
    /** @var array<string, Environment> */
    private array $environments = [];

    public function __construct(
        #[Autowire(env: 'resolve:ENVIRONMENTS_DIR')]
        private readonly string $directory,
    ) {
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
        $framework = (string) ($meta['framework'] ?? 'symfony');
        if (!\in_array($framework, Environment::FRAMEWORKS, true)) {
            throw new ContentException(sprintf('Environnement « %s » : framework « %s » inconnu (%s).', $id, $framework, implode(', ', Environment::FRAMEWORKS)));
        }
        // Symfony compile son container dans var/cache ; Laravel, ses vues Blade dans storage/framework/views ;
        // le simulateur Docker n'a pas de cache dans le projet (son état vit dans un dossier temporaire).
        $cacheDirs = $meta['cache'] ?? match ($framework) {
            'laravel' => ['storage/framework/views'],
            'docker', 'nuxt' => [],
            default => ['var/cache'],
        };

        return $this->environments[$id] = new Environment(
            id: $id,
            title: $meta['title'] ?? $id,
            // Le simulateur Nuxt tourne en JavaScript : pas de PHP à déclarer.
            phpVersion: (string) ($meta['php'] ?? ('nuxt' === $framework ? '' : throw new ContentException(sprintf('Environnement « %s » : clé « php » manquante.', $id)))),
            directory: $directory,
            framework: $framework,
            cacheDirs: array_values(array_map('strval', (array) $cacheDirs)),
        );
    }
}
