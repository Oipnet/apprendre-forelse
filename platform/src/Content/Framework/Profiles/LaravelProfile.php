<?php

namespace App\Content\Framework\Profiles;

use App\Content\Framework\FrameworkProfile;
use App\Content\Framework\FrameworkProfileProvider;

/** Laravel : artisan, les dossiers du squelette, les vues Blade compilées dans storage/. */
final class LaravelProfile implements FrameworkProfileProvider
{
    public function profile(): FrameworkProfile
    {
        return new FrameworkProfile(
            id: 'laravel',
            label: 'Laravel',
            order: 20,
            console: 'php artisan',
            consoleExample: 'route:list',
            bootNote: 'Laravel tourne entièrement dans votre navigateur, grâce à PHP compilé en WebAssembly.',
            unpackLabel: 'Décompression du projet Laravel',
            testRunner: FrameworkProfile::PHPUNIT,
            versionPackage: 'laravel/framework',
            projectDirs: ['app', 'config', 'database', 'resources', 'routes', 'tests'],
            codeDirs: ['app/', 'routes/', 'resources/views/', 'database/migrations/', 'database/seeders/', 'database/factories/', 'config/'],
            cacheDirs: ['storage/framework/views'],
            // Blade compare les dates de modification à la seconde : une vue réécrite dans la seconde resterait « fraîche ».
            testCaches: ['storage/framework/views'],
            hidden: ['vendor', 'storage', 'bootstrap/cache', 'database/database.sqlite', '.git', 'node_modules', '.phpunit.cache'],
            namespaceRoots: ['app' => 'App', 'tests' => 'Tests', 'database' => 'Database'],
            lessonLanguages: '```php, ```blade, ```bash',
            drafting: <<<'TEXTE'
                Conventions de ce projet (Laravel 13), à respecter même si tu connais d'autres façons de faire :
                - Routes dans `routes/web.php` (`Route::get('/x', [XController::class, 'index'])->name('x.index')`),
                  contrôleurs dans `app/Http/Controllers`, vues Blade dans `resources/views`, modèles Eloquent
                  dans `app/Models`, migrations dans `database/migrations`, validation par `$request->validate()`
                  ou une Form Request.
                - Les tests cachés étendent `Tests\TestCase` : `$this->get()`, `$this->post()`, `(string) $this->view()`,
                  `route('nom', absolute: false)`, `Symfony\Component\DomCrawler\Crawler` sur `$response->content()`
                  pour la structure HTML, `RefreshDatabase` dès qu'il y a une base (SQLite en mémoire). La
                  vérification CSRF est désactivée pendant les tests par Laravel lui-même.
                - `setup:` liste des commandes artisan (`migrate --force`, `db:seed`) lancées à chaque chargement
                  de l'aperçu, sur une base vide. Pas de `proc_open` ni de réseau dans le navigateur.
                - Documentation : https://laravel.com/docs/13.x/<page> (routing, controllers, blade, requests,
                  validation, eloquent, migrations…). Messages de validation déjà traduits en français.
                TEXTE,
        );
    }
}
