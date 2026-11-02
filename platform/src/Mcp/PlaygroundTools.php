<?php

namespace App\Mcp;

use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\Exercise;
use App\Scratch\ScratchFramework;
use App\Scratch\ScratchLink;
use App\Scratch\ScratchProjects;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les outils du serveur MCP (/mcp) : une IA (Claude, ChatGPT, Cursor…) fabrique un lien de bac à sable qui fait tourner
 * son code Symfony, Laravel ou PHP dans le navigateur de la personne, et relit un lien qu'on lui donne.
 *
 * Rien n'est stocké ni exécuté ici : le code part dans le fragment de l'adresse (voir ScratchLink), et la page ne le
 * lance qu'au clic du visiteur, comme pour tout lien partagé. Les descriptions sont en anglais : c'est la langue que
 * les assistants lisent le mieux, et le serveur sert les deux langues du site.
 */
final readonly class PlaygroundTools
{
    /** La source des visites dans la mesure d'audience. */
    private const array UTM = ['utm_source' => 'mcp', 'utm_medium' => 'ia', 'utm_campaign' => 'bac-a-sable'];

    public function __construct(
        private ScratchProjects $projects,
        private ContentRepository $content,
        private EnvironmentRegistry $environments,
        private ScratchLink $links,
        private UrlGeneratorInterface $urls,
        private McpAnalytics $analytics,
    ) {
    }

    /**
     * Returns the starting project of a playground (Symfony 8, Laravel 13 or plain PHP 8.4 with Composer autoloading):
     * its files, the paths you may create or edit, and, for plain PHP, the Composer packages you may add. Call it
     * before create_sandbox to write code that fits the project.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_starter', title: 'Get a playground starting project', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false))]
    public function starter(
        #[Schema(description: 'The playground: "symfony", "laravel" or "php" (no framework).')]
        ScratchFramework $framework,
        #[Schema(description: 'Language of the starting project and its page: "fr" for a French-speaking user, else "en".', enum: ['en', 'fr'])]
        string $language = 'en',
        ?RequestContext $context = null,
    ): array {
        $exercise = $this->projects->exercise($framework, $this->locale($language));
        $this->analytics->track('mcp-depart', ['framework' => $framework->value, 'langue' => $this->locale($language)], $context);

        return [
            'framework' => $framework->value,
            'url' => $this->url($framework, $language),
            'about' => $exercise->instructions,
            'open' => $exercise->open,
            'editable_paths' => $exercise->editable,
            'files' => $this->content->startingFiles($exercise),
            'packages' => $this->catalog($framework),
            'notes' => [
                'Only files matching editable_paths can be shared; "*" does not cross a "/".',
                'Framework configuration, composer.json and vendor/ are fixed, and there is no network: "about" says what the playground includes (database, console…).',
                'The preview serves the app at "/"; the user can type other addresses, like /bonjour/Ada.',
            ],
        ];
    }

    /**
     * Creates a shareable link to a playground that runs the given code in the browser (PHP in WebAssembly), with no
     * account and nothing to install. Give only the files you change or add: the others stay as in the starting
     * project (see get_starter). Nothing is stored: the code travels inside the link, after the "#". The page asks
     * the visitor before running code received by a link.
     *
     * @param array<string, mixed> $files    ce que l'assistant envoie : vérifié ici
     * @param array<mixed>         $packages
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'create_sandbox', title: 'Create a shareable PHP playground', annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false))]
    public function create(
        #[Schema(description: 'The playground: "symfony", "laravel" or "php" (no framework).')]
        ScratchFramework $framework,
        #[Schema(definition: [
            'type' => 'object',
            'description' => 'The files to change or add, by path relative to the project root (e.g. "src/Controller/AccueilController.php"), with their full content.',
            'additionalProperties' => ['type' => 'string'],
        ])]
        array $files,
        #[Schema(description: 'Plain PHP playground only: Composer packages to add, from the "packages" list of get_starter (e.g. "nesbot/carbon").', items: ['type' => 'string'])]
        array $packages = [],
        #[Schema(description: 'Language of the page: "fr" for a French-speaking user, else "en".', enum: ['en', 'fr'])]
        string $language = 'en',
        ?RequestContext $context = null,
    ): array {
        $exercise = $this->projects->exercise($framework, $this->locale($language));
        $refused = array_values(array_filter(array_keys($files), static fn (int|string $path) => !$exercise->isEditable((string) $path)));
        if ([] !== $refused) {
            throw new ToolCallException(\sprintf('These paths cannot be edited in the %s playground: %s. Editable paths: %s ("*" does not cross a "/").', $framework->label(), implode(', ', $refused), implode(', ', $exercise->editable)));
        }
        $wanted = [];
        foreach ($packages as $package) {
            if (!\is_string($package)) {
                throw new ToolCallException('Packages are given by name, e.g. "nesbot/carbon".');
            }
            $wanted[$package] = $package;
        }
        $wanted = array_values($wanted);
        $catalog = array_column($this->catalog($framework), 'name');
        $unknown = array_values(array_diff($wanted, $catalog));
        if ([] !== $unknown) {
            throw new ToolCallException([] === $catalog
                ? \sprintf('The %s playground comes with its own packages: no package can be added.', $framework->label())
                : \sprintf('Unknown packages: %s. Available: %s.', implode(', ', $unknown), implode(', ', $catalog)));
        }

        // Seuls les fichiers qui diffèrent du départ partent dans le lien : un fichier inchangé ne ferait que l'allonger.
        $starting = $this->content->startingFiles($exercise);
        $changed = [];
        $unchanged = [];
        foreach ($files as $path => $content) {
            if (!\is_string($content)) {
                throw new ToolCallException(\sprintf('The content of "%s" must be a string.', $path));
            }
            if (($starting[$path] ?? null) === $content) {
                $unchanged[] = $path;
            } else {
                $changed[$path] = $content;
            }
        }
        try {
            $fragment = $this->links->encode($changed, $wanted);
        } catch (\LengthException $e) {
            throw new ToolCallException($e->getMessage().' Share fewer or shorter files.');
        }
        $this->analytics->track('mcp-lien-cree', ['framework' => $framework->value, 'langue' => $this->locale($language), 'fichiers' => \count($changed), 'paquets' => \count($wanted)], $context);

        return [
            'url' => $this->url($framework, $language).'#'.$fragment,
            'framework' => $framework->value,
            'files' => array_keys($changed),
            'unchanged' => $unchanged,
            'packages' => $wanted,
            'next' => 'Give this link to the user: it opens the project in their browser, where they click Run to start it, then edit and share it again.',
        ];
    }

    /**
     * Reads a playground link (https://<site>/bac-a-sable/... or /en/playground/...#code=...): returns
     * the framework and the files that differ from the starting project, to review or fix the code someone shared.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'read_sandbox', title: 'Read a shared PHP playground', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false))]
    public function read(
        #[Schema(description: 'The full playground link, including the part after "#code=".')]
        string $url,
        ?RequestContext $context = null,
    ): array {
        $path = (string) parse_url(strtok($url, '#') ?: '', \PHP_URL_PATH);
        if (1 !== preg_match('~/(?:bac-a-sable|en/playground)/(symfony|laravel|php)/?$~', $path, $match)) {
            throw new ToolCallException('This is not a playground link: expected /bac-a-sable/<symfony|laravel|php> or /en/playground/<symfony|laravel|php>.');
        }
        $project = $this->links->decode($url);
        if (null === $project) {
            throw new ToolCallException('This link carries no code, or it is damaged (incomplete copy?): it must contain "#code=" followed by the whole code.');
        }

        $this->analytics->track('mcp-lien-lu', ['framework' => $match[1]], $context);

        return [
            'framework' => $match[1],
            'language' => str_contains($path, '/en/playground/') ? 'en' : 'fr',
            'files' => $project['files'],
            'packages' => $project['packages'],
            'note' => 'Only the files that differ from the starting project are listed: get_starter returns the others.',
        ];
    }

    private function locale(string $language): string
    {
        return 'fr' === $language ? Exercise::LOCALE : 'en';
    }

    private function url(ScratchFramework $framework, string $language): string
    {
        return $this->urls->generate('app_scratch_'.$framework->value, ['_locale' => $this->locale($language), ...self::UTM], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Les paquets qu'on peut ajouter : le catalogue de l'environnement (environments/<id>/paquets.json).
     *
     * @return list<array{name: string, description: string}>
     */
    private function catalog(ScratchFramework $framework): array
    {
        $file = null === $framework->packageCatalog() ? null : $this->environments->get($framework->environment())->file('paquets.json');
        if (null === $file) {
            return [];
        }
        $catalog = json_decode((string) file_get_contents($file), true);

        return array_values(array_map(
            static fn (array $package) => ['name' => (string) $package['nom'], 'description' => (string) ($package['en'] ?? '')],
            \is_array($catalog['paquets'] ?? null) ? $catalog['paquets'] : [],
        ));
    }
}
