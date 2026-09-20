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
        return $this->environments[$id] ??= $this->load($id);
    }

    private function load(string $id): Environment
    {
        // La chaîne, du plus général au plus particulier : chaque environnement peut en prolonger un
        // autre, et ses fichiers l'emportent sur ceux de sa base.
        $chain = $this->chain($id);
        /** @var array<string, mixed> $meta les clés héritées, celles de l'enfant l'emportant */
        $meta = array_merge(...array_column($chain, 'meta'));
        $directories = array_column($chain, 'directory');

        $name = (string) ($meta['framework'] ?? FrameworkRegistry::DEFAULT);
        if (!$this->frameworks->has($name)) {
            throw new ContentException(sprintf('Environnement « %s » : framework « %s » inconnu (%s).', $id, $name, implode(', ', $this->frameworks->ids())));
        }
        $framework = $this->frameworks->get($name);

        return new Environment(
            id: $id,
            // Le titre ne s'hérite pas : deux environnements ne se présentent pas sous le même nom.
            title: $chain[array_key_last($chain)]['meta']['title'] ?? $id,
            // Un environnement sans PHP (Nuxt, joué par le simulateur du navigateur) n'a pas de version à déclarer.
            phpVersion: (string) ($meta['php'] ?? ($framework->runsPhpunit() ? throw new ContentException(sprintf('Environnement « %s » : clé « php » manquante.', $id)) : '')),
            directories: $directories,
            framework: $framework,
            // Symfony compile son container dans var/cache, Laravel ses vues Blade : le profil le sait,
            // et « cache: » dans environment.yaml a le dernier mot pour un projet arrangé autrement.
            cacheDirs: array_values(array_map('strval', (array) ($meta['cache'] ?? $framework->cacheDirs))),
        );
    }

    /**
     * La chaîne d'héritage d'un environnement, de sa base la plus lointaine à lui-même.
     *
     * @param list<string> $seen les identifiants déjà traversés, pour ne pas tourner en rond
     *
     * @return list<array{directory: string, meta: array<string, mixed>}>
     */
    private function chain(string $id, array $seen = []): array
    {
        if (\in_array($id, $seen, true)) {
            throw new ContentException(sprintf('Environnements : « extends » tourne en rond (%s).', implode(' → ', [...$seen, $id])));
        }
        if (!$this->has($id)) {
            $origine = [] === $seen ? sprintf('introuvable dans %s', $this->directory) : sprintf('introuvable, prolongé par « %s »', end($seen));
            throw new ContentException(sprintf('Environnement « %s » %s.', $id, $origine));
        }

        $directory = $this->directory.'/'.$id;
        $meta = Yaml::parseFile($directory.'/environment.yaml');
        $meta = \is_array($meta) ? $meta : [];
        $entry = ['directory' => $directory, 'meta' => $meta];

        $parent = $meta['extends'] ?? null;
        if (null === $parent) {
            return [$entry];
        }
        if (!\is_string($parent) || '' === $parent) {
            throw new ContentException(sprintf('Environnement « %s » : « extends » doit nommer un autre environnement.', $id));
        }

        return [...$this->chain($parent, [...$seen, $id]), $entry];
    }
}
