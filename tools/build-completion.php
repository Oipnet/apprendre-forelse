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
    // Formulaires et validation
    'Symfony\\Component\\Form\\AbstractType',
    'Symfony\\Component\\Form\\FormBuilderInterface',
    'Symfony\\Component\\Form\\FormInterface',
    'Symfony\\Component\\Form\\Extension\\Core\\Type\\',
    'Symfony\\Bridge\\Doctrine\\Form\\Type\\EntityType',
    'Symfony\\Component\\OptionsResolver\\OptionsResolver',
    'Symfony\\Component\\Validator\\Constraints\\',
    'Symfony\\Bridge\\Doctrine\\Validator\\Constraints\\UniqueEntity',
    'Symfony\\Bridge\\Doctrine\\Attribute\\MapEntity',
    // Sécurité
    'Symfony\\Component\\Security\\Core\\User\\UserInterface',
    'Symfony\\Component\\Security\\Core\\User\\PasswordAuthenticatedUserInterface',
    'Symfony\\Component\\PasswordHasher\\Hasher\\UserPasswordHasherInterface',
    'Symfony\\Component\\Security\\Core\\Authorization\\Voter\\',
    'Symfony\\Component\\Security\\Core\\Authentication\\Token\\TokenInterface',
    'Symfony\\Component\\Security\\Http\\Attribute\\',
    'Symfony\\Component\\Security\\Http\\Authentication\\AuthenticationUtils',
    'Symfony\\Bundle\\SecurityBundle\\Security',
    'Symfony\\Component\\Security\\Core\\Validator\\Constraints\\UserPassword',
    // Messages asynchrones et tâches planifiées
    'Symfony\\Component\\Messenger\\Attribute\\',
    'Symfony\\Component\\Messenger\\MessageBusInterface',
    'Symfony\\Component\\Messenger\\Envelope',
    'Symfony\\Component\\Messenger\\Stamp\\',
    'Symfony\\Component\\Messenger\\Exception\\',
    'Symfony\\Component\\Messenger\\Transport\\InMemory\\InMemoryTransport',
    'Symfony\\Component\\Scheduler\\Attribute\\',
    'Symfony\\Component\\Scheduler\\Schedule',
    'Symfony\\Component\\Scheduler\\RecurringMessage',
    'Symfony\\Component\\Scheduler\\ScheduleProviderInterface',
    // API JSON : sérialisation, jetons d'accès
    'Symfony\\Component\\Serializer\\Attribute\\',
    'Symfony\\Component\\Serializer\\SerializerInterface',
    'Symfony\\Component\\Serializer\\Normalizer\\NormalizerInterface',
    'Symfony\\Component\\Serializer\\Normalizer\\AbstractNormalizer',
    'Symfony\\Component\\Serializer\\Normalizer\\AbstractObjectNormalizer',
    'Symfony\\Component\\Serializer\\Normalizer\\DateTimeNormalizer',
    'Symfony\\Component\\Security\\Http\\AccessToken\\AccessTokenHandlerInterface',
    'Symfony\\Component\\Security\\Http\\Authenticator\\Passport\\Badge\\UserBadge',
    'Symfony\\Component\\Security\\Core\\Exception\\BadCredentialsException',
    'Symfony\\Component\\Security\\Core\\Exception\\AuthenticationException',
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
    'Illuminate\\Bus\\Queueable',
    'Illuminate\\Queue\\InteractsWithQueue',
    'Illuminate\\Queue\\SerializesModels',
    'Illuminate\\Notifications\\Notification',
    'Illuminate\\Notifications\\Notifiable',
    'Illuminate\\Mail\\Mailable',
    'Illuminate\\Events\\Dispatcher',
    // Classes du projet Laravel dont héritent contrôleurs et tests
    'App\\Http\\Controllers\\Controller',
    'Tests\\TestCase',
];
const EXCLUDED = ['\\Tests\\', '\\Session\\Storage\\Handler\\'];

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
            'params' => array_map(param(...), $m->getParameters()),
            'returns' => typeName($m->getReturnType()),
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

    $index['classes'][$fqcn] = [
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

ksort($index['classes']);
file_put_contents($output, json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
fprintf(STDERR, "%d classes indexées\n", count($index['classes']));
