<?php

namespace App\Content;

use App\Content\Framework\FrameworkRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Les environnements d'exécution disponibles, lus dans les dossiers d'ENVIRONMENTS_DIR.
 *
 * Trois provenances, dans cet ordre :
 *  1. les dossiers d'ENVIRONMENTS_DIR — ceux que le moteur livre, et ceux qu'une instance installe
 *     elle-même (voir App\Instance\InstalledEnvironments) ;
 *  2. `<pack>/environments/<id>/` — **l'environnement que le pack porte lui-même**. Un décor appartient
 *     au contenu qui le met en scène : il se déplace avec lui, se versionne avec lui, et n'a ni dépôt
 *     ni déclaration à tenir à jour. C'est le cas ordinaire pour un pack qui a besoin d'autre chose
 *     qu'un squelette générique.
 *
 * Un identifiant déclaré deux fois est une erreur, nommant les deux dossiers : un environnement qui en
 * masquerait un autre en silence serait indébuggable — et un pack ne peut donc pas s'approprier
 * « symfony-8 » sans le dire.
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

    /** @var list<string> */
    private readonly array $packPaths;

    /**
     * @param list<string>|string $directories dossiers d'environnements, dans l'ordre de recherche
     * @param list<string>|string $packPaths   dossiers de packs, fouillés pour leur `environments/`
     */
    public function __construct(
        #[Autowire(env: 'csv:resolve:ENVIRONMENTS_DIR')]
        array|string $directories,
        ?FrameworkRegistry $frameworks = null,
        #[Autowire(env: 'csv:resolve:CONTENT_PACKS_PATHS')]
        array|string $packPaths = [],
    ) {
        $this->directories = array_values(array_filter(array_map('trim', (array) $directories)));
        $this->packPaths = array_values(array_filter(array_map('trim', (array) $packPaths)));
        $this->frameworks = $frameworks ?? new FrameworkRegistry();
    }

    /**
     * Tous les dossiers fouillés, dans l'ordre : ceux d'ENVIRONMENTS_DIR, puis les `environments/` des
     * packs. C'est aussi ce qu'il faut passer à build-env.sh (ENVIRONMENTS_PATH) pour qu'il résolve
     * les mêmes chaînes que le moteur.
     *
     * @return list<string>
     */
    public function roots(): array
    {
        return [...$this->directories, ...$this->packRoots()];
    }

    /**
     * Les `environments/` des packs montés. Un chemin de CONTENT_PACKS_PATHS désigne un pack, ou un
     * dossier qui en contient plusieurs — même règle que ContentRepository, sans dépendre de lui : ce
     * service est construit avant, et le contenu a besoin de lui pour se charger.
     *
     * @return list<string>
     */
    private function packRoots(): array
    {
        $roots = [];
        foreach ($this->packPaths as $path) {
            $path = rtrim($path, '/');
            $packs = is_file($path.'/pack.yaml') ? [$path] : (glob($path.'/*', \GLOB_ONLYDIR) ?: []);
            foreach ($packs as $pack) {
                if (is_file($pack.'/pack.yaml') && is_dir($pack.'/environments')) {
                    $roots[] = $pack.'/environments';
                }
            }
        }

        return $roots;
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
        foreach ($this->roots() as $directory) {
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

    /**
     * Les environnements qu'un pack porte lui-même, par identifiant => dossier.
     *
     * Ils sont déjà sur le disque, arrivés avec le pack ; il ne leur manque que leur archive, que
     * `app:environnement:synchroniser` construit (voir App\Instance\PackEnvironments).
     *
     * @return array<string, string>
     */
    public function carried(): array
    {
        $racines = $this->packRoots();
        $portes = [];
        foreach ($this->byId() as $id => $directory) {
            if (\in_array(\dirname($directory), $racines, true)) {
                $portes[$id] = $directory;
            }
        }

        return $portes;
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
                ? sprintf('introuvable dans %s', implode(', ', $this->roots()))
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
