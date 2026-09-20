<?php

namespace App\Content\Framework\Profiles;

use App\Content\Framework\FrameworkProfile;
use App\Content\Framework\FrameworkProfileProvider;

/** Symfony : la console, les dossiers du squelette, le cache compilé dans var/. */
final class SymfonyProfile implements FrameworkProfileProvider
{
    public function profile(): FrameworkProfile
    {
        return new FrameworkProfile(
            id: 'symfony',
            label: 'Symfony',
            order: 10,
            console: 'bin/console',
            consoleExample: 'debug:router',
            bootNote: 'Symfony tourne entièrement dans votre navigateur, grâce à PHP compilé en WebAssembly.',
            unpackLabel: 'Décompression du projet Symfony',
            testRunner: FrameworkProfile::PHPUNIT,
            versionPackage: 'symfony/framework-bundle',
            projectDirs: ['src', 'migrations', 'templates', 'config', 'tests', 'translations'],
            codeDirs: ['src/', 'config/packages/', 'templates/'],
            cacheDirs: ['var/cache'],
            testCaches: ['var/cache/test'],
            hidden: ['vendor', 'var', '.git', 'node_modules', '.phpunit.cache'],
            namespaceRoots: ['src' => 'App', 'tests' => 'App\\Tests', 'migrations' => 'DoctrineMigrations'],
            lessonLanguages: '```php, ```twig, ```yaml, ```bash',
            drafting: <<<'TEXTE'
                Conventions de ce projet (Symfony 8.1), à respecter même si tu connais d'autres façons de faire :
                - Attributs PHP, autowiring, services ; pas de YAML de routage.
                - Les commandes console sont **invocables** : une classe `final` avec `#[AsCommand]` et une
                  méthode `__invoke(SymfonyStyle $io, ...)`. Elles n'étendent jamais `Command` et n'ont ni
                  `configure()` ni `execute()`. Les entrées se déclarent en arguments de `__invoke` :
                  `#[Argument('Description')] ?string $guilde = null` et `#[Option('Description')] int $jours = 7`
                  (`Symfony\Component\Console\Attribute\Argument` et `…\Attribute\Option`). Le retour est
                  `Command::SUCCESS`.
                - Les dépendances arrivent par le constructeur, en propriétés promues `private readonly`.
                - Dans les tests, `enregistrer(...$objets)` vide l'EntityManager après coup : enregistre des
                  entités liées entre elles **en un seul appel**, sinon Doctrine refuse une association vers
                  une entité détachée.
                TEXTE,
        );
    }
}
