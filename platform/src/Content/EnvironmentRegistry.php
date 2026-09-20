<?php

namespace App\Content;

use App\Content\Framework\FrameworkRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Les environnements d'exécution disponibles, lus dans les dossiers d'ENVIRONMENTS_DIR.
 *
 * Plusieurs dossiers, comme pour les packs (CONTENT_PACKS_PATHS) : ceux que le moteur livre, et ceux
 * qu'une instance installe elle-même (voir App\Instance\InstalledEnvironments). Un identifiant déclaré
 * deux fois est une erreur, nommant les deux dossiers : un environnement qui en masquerait un autre en
 * silence serait indébuggable.
 */
final class EnvironmentRegistry
{
    /** @var array<string, Environment> */
    private array $environments = [];
    /** @var array<string, string>|null identifiant => dossier, tous dossiers confondus */
    private ?array $directoriesById = null;

    private readonly FrameworkRegistry $frameworks;
    /** @var list<string> */
    private readonly array $directories;

    /**
     * @param list<string>|string $directories dossiers d'environnements, dans l'ordre de recherche
     */
    public function __construct(
        #[Autowire(env: 'csv:resolve:ENVIRONMENTS_DIR')]
        array|string $directories,
        ?FrameworkRegistry $frameworks = null,
    ) {
        $this->directories = array_values(array_filter(array_map('trim', (array) $directories)));
        $this->frameworks = $frameworks ?? new FrameworkRegistry();
    }

    public function has(string $id): bool
    {
        return isset($this->byId()[$id]);
    }

    /**
     * Où vit chaque environnement. Construit une fois : un identifiant en double arrête tout.
     *
     * @return array<string, string>
     */
    private function byId(): array
    {
        if (null !== $this->directoriesById) {
            return $this->directoriesById;
        }
        $found = [];
        foreach ($this->directories as $directory) {
            foreach (glob($directory.'/*/environment.yaml') ?: [] as $manifest) {
                $id = basename(\dirname($manifest));
                if (isset($found[$id])) {
                    throw new ContentException(sprintf('Environnement « %s » déclaré deux fois (%s et %s).', $id, $found[$id], \dirname($manifest)));
                }
                $found[$id] = \dirname($manifest);
            }
        }

        return $this->directoriesById = $found;
    }

    /** Oublie ce qui a été lu : à appeler après avoir installé ou retiré un environnement. */
    public function reset(): void
    {
        $this->environments = [];
        $this->directoriesById = null;
    }

    /** @return array<string, Environment> tous les environnements disponibles, par identifiant */
    public function all(): array
    {
        $all = [];
        foreach (array_keys($this->byId()) as $id) {
            $all[$id] = $this->get($id);
        }

        return $all;
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
            $origine = [] === $seen
                ? sprintf('introuvable dans %s', implode(', ', $this->directories))
                : sprintf('introuvable, prolongé par « %s »', end($seen));
            throw new ContentException(sprintf('Environnement « %s » %s.', $id, $origine));
        }

        $directory = $this->byId()[$id];
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
