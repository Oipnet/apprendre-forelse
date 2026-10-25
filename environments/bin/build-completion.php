<?php

/**
 * Génère l'index de complétion d'un environnement par Reflection.
 * Usage : php tools/build-completion.php <dossier-environnement> <sortie.json>
 *
 * On ne réfléchit que des namespaces utiles à l'apprenant : charger tout vendor/
 * déclencherait des erreurs fatales sur les dépendances optionnelles absentes.
 */

[, $envDir, $output] = $argv + [null, null, null];
if (!$envDir || !$output) {
    fwrite(STDERR, "usage: php build-completion.php <env-dir> <output.json>\n");
    exit(1);
}

$classmap = require $envDir.'/vendor/composer/autoload_classmap.php';
require $envDir.'/vendor/autoload.php';

const NAMESPACES = [
    'Symfony\\Bundle\\FrameworkBundle\\Controller\\',
    'Symfony\\Bundle\\FrameworkBundle\\Test\\',
    'Symfony\\Component\\HttpFoundation\\',
    'Symfony\\Component\\HttpKernel\\Attribute\\',
    'Symfony\\Component\\HttpKernel\\Exception\\',
    'Symfony\\Component\\Routing\\Attribute\\',
    'Symfony\\Component\\Routing\\Generator\\UrlGeneratorInterface',
    'Symfony\\Component\\DependencyInjection\\Attribute\\',
    'Symfony\\Component\\DomCrawler\\Crawler',
    'Symfony\\Component\\BrowserKit\\AbstractBrowser',
    'Symfony\\Bundle\\FrameworkBundle\\KernelBrowser',
    'PHPUnit\\Framework\\TestCase',
    'PHPUnit\\Framework\\Assert',
    'PHPUnit\\Framework\\Attributes\\',
    'Symfony\\Component\\String\\Slugger\\',
    // Doctrine (environnements qui l'embarquent ; absents ailleurs, simplement ignorés)
    'Doctrine\\ORM\\Mapping\\',
    'Doctrine\\ORM\\EntityManagerInterface',
    'Doctrine\\ORM\\EntityRepository',
    'Doctrine\\ORM\\QueryBuilder',
    'Doctrine\\ORM\\Query',
    'Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository',
    'Doctrine\\Persistence\\ManagerRegistry',
    'Doctrine\\Persistence\\ObjectManager',
    'Doctrine\\Common\\Collections\\',
    'Doctrine\\DBAL\\Types\\Types',
    'Doctrine\\DBAL\\Schema\\Schema',
    'Doctrine\\Migrations\\AbstractMigration',
    'Doctrine\\ORM\\Event\\',
    'Doctrine\\ORM\\Events',
    'Doctrine\\ORM\\Tools\\Pagination\\Paginator',
    'Doctrine\\Bundle\\DoctrineBundle\\Attribute\\',
    // Formulaires et validation
    'Symfony\\Component\\Form\\AbstractType',
    'Symfony\\Component\\Form\\AbstractTypeExtension',
    'Symfony\\Component\\Form\\Attribute\\',
    'Symfony\\Component\\Form\\FormBuilderInterface',
    'Symfony\\Component\\Form\\FormInterface',
    'Symfony\\Component\\Form\\FormError',
    'Symfony\\Component\\Form\\Extension\\Core\\Type\\',
    'Symfony\\Bridge\\Doctrine\\Form\\Type\\EntityType',
    'Symfony\\Component\\OptionsResolver\\OptionsResolver',
    'Symfony\\Component\\Validator\\Constraints\\',
    'Symfony\\Component\\Validator\\Constraint',
    'Symfony\\Component\\Validator\\ConstraintValidator',
    'Symfony\\Component\\Validator\\Context\\ExecutionContextInterface',
    'Symfony\\Component\\Validator\\Exception\\',
    'Symfony\\Component\\Validator\\Validator\\ValidatorInterface',
    'Symfony\\Bridge\\Doctrine\\Validator\\Constraints\\UniqueEntity',
    'Symfony\\Bridge\\Doctrine\\Attribute\\MapEntity',
    // Sécurité
    'Symfony\\Component\\Security\\Core\\User\\',
    'Symfony\\Component\\PasswordHasher\\Hasher\\UserPasswordHasherInterface',
    'Symfony\\Component\\Security\\Core\\Authorization\\Voter\\',
    'Symfony\\Component\\Security\\Core\\Authentication\\Token\\TokenInterface',
    'Symfony\\Component\\Security\\Http\\Attribute\\',
    'Symfony\\Component\\Security\\Http\\Event\\',
    'Symfony\\Component\\Security\\Http\\Authentication\\AuthenticationUtils',
    'Symfony\\Bundle\\SecurityBundle\\Security',
    'Symfony\\Component\\Security\\Core\\Validator\\Constraints\\UserPassword',
    // Authenticators : passeports, badges, et les méthodes d'authentification prouvées (Symfony 8.2)
    'Symfony\\Component\\Security\\Http\\Authenticator\\',
    'Symfony\\Component\\Security\\Http\\EntryPoint\\',
    'Symfony\\Component\\Security\\Http\\SecurityRequestAttributes',
    'Symfony\\Component\\Security\\Core\\Authentication\\AuthenticationMethod',
    'Symfony\\Component\\Security\\Core\\Exception\\CustomUserMessageAuthenticationException',
    // Messages asynchrones et tâches planifiées
    'Symfony\\Component\\Messenger\\Attribute\\',
    'Symfony\\Component\\Messenger\\MessageBusInterface',
    'Symfony\\Component\\Messenger\\Envelope',
    'Symfony\\Component\\Messenger\\Stamp\\',
    'Symfony\\Component\\Messenger\\Exception\\',
    'Symfony\\Component\\RateLimiter\\',
    'Symfony\\Component\\Messenger\\Transport\\InMemory\\InMemoryTransport',
    'Symfony\\Component\\Scheduler\\Attribute\\',
    'Symfony\\Component\\Scheduler\\Schedule',
    'Symfony\\Component\\Scheduler\\RecurringMessage',
    'Symfony\\Component\\Scheduler\\ScheduleProviderInterface',
    // API JSON : sérialisation, jetons d'accès
    'Symfony\\Component\\Serializer\\Attribute\\',
    'Symfony\\Component\\Serializer\\Encoder\\',
    'Symfony\\Component\\Serializer\\SerializerInterface',
    'Symfony\\Component\\Serializer\\Normalizer\\NormalizerInterface',
    'Symfony\\Component\\Serializer\\Normalizer\\AbstractNormalizer',
    'Symfony\\Component\\Serializer\\Normalizer\\AbstractObjectNormalizer',
    'Symfony\\Component\\Serializer\\Normalizer\\DateTimeNormalizer',
    'Symfony\\Component\\Security\\Http\\AccessToken\\AccessTokenHandlerInterface',
    'Symfony\\Component\\Security\\Http\\Authenticator\\Passport\\Badge\\UserBadge',
    'Symfony\\Component\\Security\\Core\\Exception\\BadCredentialsException',
    'Symfony\\Component\\Security\\Core\\Exception\\AuthenticationException',
    // Événements, workflow, horloge, cache, courriels, Twig
    'Symfony\\Component\\EventDispatcher\\Attribute\\',
    'Symfony\\Component\\EventDispatcher\\EventDispatcherInterface',
    'Symfony\\Component\\HttpKernel\\Event\\',
    'Symfony\\Component\\HttpKernel\\KernelEvents',
    // Les écouteurs du noyau : les cibles des « before »/« after » de #[AsEventListener] (Symfony 8.2)
    'Symfony\\Component\\HttpKernel\\EventListener\\',
    'Symfony\\Component\\Workflow\\WorkflowInterface',
    'Symfony\\Component\\Workflow\\Workflow',
    'Symfony\\Component\\Workflow\\Transition',
    'Symfony\\Component\\Workflow\\TransitionBlocker',
    'Symfony\\Component\\Workflow\\Attribute\\',
    'Symfony\\Component\\Workflow\\Event\\',
    'Symfony\\Component\\Workflow\\WorkflowTrait',
    'Symfony\\Component\\Workflow\\WorkflowType',
    // KeyManagement : colonnes chiffrées et index aveugles (symfony-8-2-doctrine-dev)
    'Symfony\\Component\\KeyManagement\\Bridge\\DoctrineOrm\\Attribute\\',
    'Symfony\\Component\\KeyManagement\\BlindIndex',
    'Symfony\\Component\\PropertyAccess\\PropertyAccess',
    'Symfony\\Component\\PropertyAccess\\PropertyAccessorInterface',
    // Verrous, traductions sûres et schémas JSON (Symfony 8.2)
    'Symfony\\Component\\Lock\\LockFactory',
    'Symfony\\Component\\Lock\\LockInterface',
    'Symfony\\Component\\Translation\\HtmlTranslator',
    'Symfony\\Contracts\\Translation\\TranslatorInterface',
    'Symfony\\Component\\JsonSchema\\Attribute\\',
    'Symfony\\Component\\JsonSchema\\Schema',
    'Symfony\\Component\\JsonSchema\\SchemaGeneratorInterface',
    'Psr\\Clock\\ClockInterface',
    'Symfony\\Component\\Clock\\Clock',
    'Symfony\\Component\\Clock\\MockClock',
    'Symfony\\Contracts\\Cache\\CacheInterface',
    'Symfony\\Contracts\\Cache\\ItemInterface',
    'Symfony\\Component\\Mailer\\MailerInterface',
    'Symfony\\Component\\Mime\\Address',
    'Symfony\\Component\\Mime\\Email',
    'Symfony\\Bridge\\Twig\\Mime\\TemplatedEmail',
    'Twig\\Environment',
    // Console : commandes, entrées et sorties, tests de commandes
    'Symfony\\Component\\Console\\Attribute\\',
    'Symfony\\Component\\Console\\Command\\Command',
    'Symfony\\Component\\Console\\CommandChain',
    'Symfony\\Component\\Console\\Input\\InputInterface',
    'Symfony\\Component\\Console\\Input\\InputOption',
    'Symfony\\Component\\Console\\Input\\InputArgument',
    'Symfony\\Component\\Console\\Output\\OutputInterface',
    'Symfony\\Component\\Console\\Style\\SymfonyStyle',
    'Symfony\\Component\\Console\\Tester\\CommandTester',
    'Symfony\\Component\\Console\\Tester\\ApplicationTester',
    'Symfony\\Bundle\\FrameworkBundle\\Console\\Application',
    // EasyAdmin (symfony-8-easyadmin ; absent ailleurs, simplement ignoré)
    'EasyCorp\\Bundle\\EasyAdminBundle\\Attribute\\',
    'EasyCorp\\Bundle\\EasyAdminBundle\\Config\\',
    'EasyCorp\\Bundle\\EasyAdminBundle\\Controller\\',
    'EasyCorp\\Bundle\\EasyAdminBundle\\Field\\',
    'EasyCorp\\Bundle\\EasyAdminBundle\\Filter\\',
    'EasyCorp\\Bundle\\EasyAdminBundle\\Router\\AdminUrlGenerator',
    'EasyCorp\\Bundle\\EasyAdminBundle\\Event\\',
    'EasyCorp\\Bundle\\EasyAdminBundle\\Context\\AdminContext',
    // Laravel (environnements laravel-* ; absents ailleurs, simplement ignorés)
    'Illuminate\\Http\\Request',
    'Illuminate\\Http\\Response',
    'Illuminate\\Http\\RedirectResponse',
    'Illuminate\\Http\\JsonResponse',
    'Illuminate\\Http\\Resources\\Json\\',
    'Illuminate\\Routing\\Controller',
    'Illuminate\\Routing\\Route',
    'Illuminate\\Routing\\Router',
    'Illuminate\\Routing\\Redirector',
    'Illuminate\\Routing\\UrlGenerator',
    'Illuminate\\Support\\Facades\\',
    'Illuminate\\Support\\Str',
    'Illuminate\\Support\\Arr',
    'Illuminate\\Support\\Collection',
    'Illuminate\\Support\\Carbon',
    'Illuminate\\Support\\ServiceProvider',
    'Illuminate\\Support\\MessageBag',
    'Illuminate\\Session\\Store',
    'Illuminate\\Database\\Eloquent\\Model',
    'Illuminate\\Database\\Eloquent\\Builder',
    'Illuminate\\Database\\Eloquent\\Collection',
    'Illuminate\\Database\\Eloquent\\Factories\\',
    'Illuminate\\Database\\Eloquent\\Relations\\',
    'Illuminate\\Database\\Eloquent\\Casts\\',
    'Illuminate\\Database\\Eloquent\\Attributes\\',
    'Illuminate\\Database\\Eloquent\\SoftDeletes',
    'Illuminate\\Database\\Eloquent\\Concerns\\HasDefaultAttributes',
    'Illuminate\\Database\\Eloquent\\ModelNotFoundException',
    'Illuminate\\Database\\Query\\Builder',
    'Illuminate\\Database\\Schema\\Blueprint',
    'Illuminate\\Database\\Schema\\Builder',
    'Illuminate\\Database\\Migrations\\Migration',
    'Illuminate\\Database\\Seeder',
    'Illuminate\\Foundation\\Testing\\TestCase',
    'Illuminate\\Foundation\\Testing\\RefreshDatabase',
    'Illuminate\\Foundation\\Http\\FormRequest',
    'Illuminate\\Foundation\\Auth\\User',
    'Illuminate\\Foundation\\Auth\\Access\\AuthorizesRequests',
    'Illuminate\\Foundation\\Bus\\Dispatchable',
    'Illuminate\\Foundation\\Events\\Dispatchable',
    'Illuminate\\Testing\\TestResponse',
    'Illuminate\\Testing\\TestView',
    'Illuminate\\Validation\\Rule',
    'Illuminate\\Validation\\Rules\\',
    'Illuminate\\Validation\\ValidationException',
    'Illuminate\\Validation\\Validator',
    'Illuminate\\View\\View',
    'Illuminate\\View\\Component',
    'Illuminate\\Contracts\\View\\View',
    'Illuminate\\Contracts\\Queue\\ShouldQueue',
    'Illuminate\\Contracts\\Auth\\Authenticatable',
    'Illuminate\\Auth\\Access\\',
    'Illuminate\\Console\\Command',
    'Illuminate\\Console\\Attributes\\',
    'Illuminate\\Console\\Scheduling\\Schedule',
    'Illuminate\\Foundation\\Http\\Middleware\\PreventStaleDatabaseReads',
    'Illuminate\\Bus\\Queueable',
    'Illuminate\\Queue\\InteractsWithQueue',
    'Illuminate\\Queue\\SerializesModels',
    'Illuminate\\Notifications\\Notification',
    'Illuminate\\Notifications\\Notifiable',
    'Illuminate\\Mail\\Mailable',
    'Illuminate\\Events\\Dispatcher',
    'Illuminate\\Foundation\\Application',
    'Illuminate\\Foundation\\Configuration\\',
    'Illuminate\\Foundation\\Inspiring',
    'Illuminate\\Foundation\\Queue\\Queueable',
    'Illuminate\\Queue\\Attributes\\',
    'Illuminate\\Contracts\\Validation\\',
    'Carbon\\Carbon',
    'Carbon\\CarbonImmutable',
    'Carbon\\CarbonInterval',
    'Carbon\\Exceptions\\',
    // Classes du projet Laravel dont héritent contrôleurs et tests
    'App\\Http\\Controllers\\Controller',
    'Tests\\TestCase',
];
const EXCLUDED = ['\\Tests\\', '\\Session\\Storage\\Handler\\'];

// Classes natives de PHP : absentes du classmap de Composer, on les nomme une à une.
// Une extension absente de la machine qui construit l'index (intl…) est simplement ignorée.
const NATIVES = [
    // Dates
    'DateTimeInterface', 'DateTime', 'DateTimeImmutable', 'DateInterval', 'DatePeriod', 'DateTimeZone',
    // Exceptions et erreurs
    'Throwable', 'Exception', 'Error', 'ErrorException', 'TypeError', 'ValueError', 'ArgumentCountError',
    'ArithmeticError', 'DivisionByZeroError', 'JsonException', 'UnhandledMatchError',
    'LogicException', 'BadFunctionCallException', 'BadMethodCallException', 'DomainException',
    'InvalidArgumentException', 'LengthException', 'OutOfRangeException',
    'RuntimeException', 'OutOfBoundsException', 'OverflowException', 'RangeException',
    'UnderflowException', 'UnexpectedValueException',
    // Interfaces et structures courantes
    'Stringable', 'JsonSerializable', 'Countable', 'ArrayAccess', 'Traversable', 'Iterator',
    'IteratorAggregate', 'Generator', 'Closure', 'stdClass', 'WeakMap', 'WeakReference',
    'UnitEnum', 'BackedEnum', 'ArrayObject', 'ArrayIterator', 'SplObjectStorage', 'SplQueue', 'SplStack',
    // Attributs du langage
    'Attribute', 'Override', 'SensitiveParameter', 'AllowDynamicProperties',
    // Divers
    'Random\\Randomizer', 'IntlDateFormatter', 'NumberFormatter', 'Collator',
];

function summary(string|false $doc): string
{
    if (!$doc) {
        return '';
    }
    $lines = [];
    foreach (preg_split('/\R/', $doc) as $line) {
        $line = trim(preg_replace('#^\s*(/\*\*|\*/|\*)#', '', $line));
        if ('' === $line) {
            if ($lines) {
                break;
            }
            continue;
        }
        if (str_starts_with($line, '@')) {
            break;
        }
        $lines[] = $line;
    }

    return implode(' ', $lines);
}

function typeName(?ReflectionType $type): string
{
    return $type ? (string) $type : '';
}

function param(ReflectionParameter $p): string
{
    $s = trim(typeName($p->getType()).' '.($p->isVariadic() ? '...' : '').'$'.$p->getName());
    if ($p->isDefaultValueAvailable()) {
        $default = $p->isDefaultValueConstant() ? null : $p->getDefaultValue();
        $s .= ' = '.match (true) {
            $p->isDefaultValueConstant() => $p->getDefaultValueConstantName(),
            [] === $default => '[]',
            is_array($default) => '[…]',
            null === $default => 'null',
            default => var_export($default, true),
        };
    }

    return $s;
}

/**
 * Paramètres ajoutés en commentaire, à la manière de Symfony pour ne pas casser les classes filles :
 * `sign(string $uri, … $expiration = null/* , ?string $version = null *\/)`, lus par
 * func_get_arg(). Reflection ne les voit pas ; l'apprenant, lui, doit pouvoir les passer.
 *
 * @return list<string>
 */
function commentedParams(ReflectionMethod $m): array
{
    $file = $m->getFileName();
    if (!$file) {
        return [];
    }
    $source = implode('', array_slice(file($file), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    $declaration = strstr($source, '{', true) ?: $source;
    if (!preg_match('#/\*\s*,\s*(.+?)\s*\*/#s', $declaration, $found)) {
        return [];
    }
    $params = preg_replace('/#\[[^\]]*\]\s*/', '', $found[1]);

    // `\DateInterval|int` → `DateInterval|int`, comme les types que rend Reflection.
    return array_map(static fn (string $p) => preg_replace('/(^|[|?(\s])\\\\/', '$1', trim($p)), explode(',', $params));
}

function describe(ReflectionClass $rc): array
{
    $methods = [];
    foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $m) {
        if (str_starts_with($m->getName(), '__') && '__construct' !== $m->getName()) {
            continue;
        }
        if (str_contains((string) $m->getDocComment(), '@internal')) {
            continue;
        }
        $methods[$m->getName()] = [
            'name' => $m->getName(),
            'params' => [...array_map(param(...), $m->getParameters()), ...commentedParams($m)],
            // Méthodes natives : leur type de retour n'est souvent que « provisoire » (DateTime::add() : DateTime).
            'returns' => typeName($m->getReturnType() ?? ($m->hasTentativeReturnType() ? $m->getTentativeReturnType() : null)),
            'static' => $m->isStatic(),
            'protected' => $m->isProtected(),
            'declaringClass' => $m->getDeclaringClass()->getShortName(),
            'doc' => summary($m->getDocComment()),
        ];
    }

    // Façades Laravel (et toute classe documentée par @method) : des méthodes que Reflection ne voit pas.
    if (preg_match_all('/@method\s+(static\s+)?(\S+)\s+(\w+)\(([^)]*)\)[ \t]*([^\n]*)/', (string) $rc->getDocComment(), $annotated, PREG_SET_ORDER)) {
        foreach ($annotated as [, $static, $returns, $name, $params, $doc]) {
            $methods[$name] ??= [
                'name' => $name,
                'params' => '' === trim($params) ? [] : array_map('trim', explode(',', $params)),
                'returns' => ltrim($returns, '\\'),
                'static' => '' !== $static,
                'protected' => false,
                'declaringClass' => $rc->getShortName(),
                'doc' => trim($doc),
            ];
        }
    }

    $kind = match (true) {
        [] !== $rc->getAttributes(Attribute::class) => 'attribute',
        $rc->isInterface() => 'interface',
        $rc->isTrait() => 'trait',
        $rc->isEnum() => 'enum',
        default => 'class',
    };

    return [
        'short' => $rc->getShortName(),
        'kind' => $kind,
        'abstract' => $rc->isAbstract() && !$rc->isInterface(),
        'doc' => summary($rc->getDocComment()),
        'constructor' => isset($methods['__construct']) ? $methods['__construct']['params'] : [],
        'constants' => array_values(array_map(
            static fn (ReflectionClassConstant $c) => $c->getName(),
            array_filter($rc->getReflectionConstants(), static fn (ReflectionClassConstant $c) => $c->isPublic()),
        )),
        'methods' => array_values(array_filter($methods, static fn ($m) => '__construct' !== $m['name'])),
    ];
}

$index = ['generatedAt' => date(DATE_ATOM), 'classes' => []];

foreach (array_keys($classmap) as $fqcn) {
    $wanted = false;
    foreach (NAMESPACES as $ns) {
        if (str_starts_with($fqcn, $ns)) {
            $wanted = true;
            break;
        }
    }
    foreach (EXCLUDED as $ex) {
        if (str_contains($fqcn, $ex)) {
            $wanted = false;
        }
    }
    if (!$wanted) {
        continue;
    }

    try {
        $rc = new ReflectionClass($fqcn);
    } catch (Throwable) {
        continue;
    }
    if ($rc->isAnonymous() || $rc->isInternal() || str_contains((string) $rc->getDocComment(), '@internal')) {
        continue;
    }
    $index['classes'][$fqcn] = describe($rc);
}

foreach (NATIVES as $fqcn) {
    if (class_exists($fqcn) || interface_exists($fqcn)) {
        $index['classes'][$fqcn] = describe(new ReflectionClass($fqcn));
    }
}


// Les imports de namespace (use X\\Constraints; puis Constraints\\NotBlank) sont
// des cibles de complétion valides : on indexe aussi chaque préfixe de namespace.
foreach (NAMESPACES as $ns) {
    if (str_ends_with($ns, '\\')) {
        $nom = rtrim($ns, '\\');
        $index['classes'][$nom] ??= [
            'short' => substr($nom, strrpos($nom, '\\') + 1),
            'kind' => 'namespace',
            'abstract' => false,
            'doc' => '',
            'constructor' => [],
            'constants' => [],
            'methods' => [],
        ];
    }
}

// Fonctions : celles de PHP (extensions courantes) et les helpers globaux des frameworks (route(), view(), dd()…).
// Une extension absente de la machine qui construit l'index est simplement ignorée.
const FUNCTION_EXTENSIONS = ['Core', 'standard', 'date', 'pcre', 'json', 'mbstring', 'ctype', 'SPL', 'random', 'hash', 'filter', 'intl'];
// Helpers retenus d'après leur fichier : pas les assertTrue() globaux de PHPUnit, ni les polyfills.
const FUNCTION_SOURCES = ['/laravel/framework/', '/symfony/var-dumper/'];

$index['functions'] = [];
$functions = [];
foreach (FUNCTION_EXTENSIONS as $extension) {
    array_push($functions, ...(get_extension_funcs($extension) ?: []));
}
foreach (get_defined_functions()['user'] as $name) {
    $file = (string) (new ReflectionFunction($name))->getFileName();
    foreach (FUNCTION_SOURCES as $source) {
        if (str_contains($file, $source)) {
            $functions[] = $name;
            break;
        }
    }
}
foreach ($functions as $name) {
    $rf = new ReflectionFunction($name);
    // Fonctions dépréciées, ou d'un namespace (Laravel\Prompts\…) : à ne pas suggérer sans namespace.
    if ($rf->isDeprecated() || $rf->inNamespace() || str_starts_with($rf->getName(), '_')) {
        continue;
    }
    $index['functions'][$rf->getName()] = [
        'params' => array_map(param(...), $rf->getParameters()),
        'returns' => typeName($rf->getReturnType() ?? ($rf->hasTentativeReturnType() ? $rf->getTentativeReturnType() : null)),
        'doc' => summary($rf->getDocComment()),
    ];
}

ksort($index['classes']);
ksort($index['functions']);
file_put_contents($output, json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
fprintf(STDERR, "%d classes et %d fonctions indexées\n", count($index['classes']), count($index['functions']));
