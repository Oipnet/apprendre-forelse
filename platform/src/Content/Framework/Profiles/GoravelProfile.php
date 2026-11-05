<?php

namespace App\Content\Framework\Profiles;

use App\Content\Framework\FrameworkProfile;
use App\Content\Framework\FrameworkProfileProvider;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Goravel (Go), servi par packages/runtime-goravel : le framework compilé en WebAssembly, le code de
 * l'apprenant (routes, contrôleurs, modèles, tests) interprété par yaegi à chaque démarrage. Ses tests sont
 * des fonctions TestXxx de Go, lancées par le paquet testing : dans le worker par le navigateur, et sous Node,
 * avec le même binaire, par content:check.
 */
final class GoravelProfile implements FrameworkProfileProvider
{
    public function profile(): FrameworkProfile
    {
        return new FrameworkProfile(
            id: 'goravel',
            label: 'Goravel',
            order: 60,
            console: 'go run . artisan',
            consoleExample: 'route:list',
            bootNote: new TranslatableMessage('playground.framework.goravel.demarrage', [], 'apprentissage'),
            unpackLabel: new TranslatableMessage('playground.framework.goravel.decompression', [], 'apprentissage'),
            testRunner: FrameworkProfile::GOTEST,
            // La version de Goravel est celle du binaire (packages/runtime-goravel/go/go.mod), pas d'un fichier du projet.
            versionPackage: null,
            projectDirs: ['app', 'routes', 'resources', 'tests', 'public'],
            codeDirs: ['app/', 'routes/'],
            cacheDirs: [],
            testCaches: [],
            // storage/ : journaux et bases SQLite, que l'application écrit.
            hidden: ['storage', 'vendor', '.git'],
            // Pas de PHP : aucun fichier créé dans l'explorateur ne reçoit de namespace.
            namespaceRoots: [],
            lessonLanguages: '```go, ```bash',
            // L'atelier ne sait pas encore échafauder ni faire rédiger un exercice Goravel : voir ExerciseDrafter.
            drafting: null,
            runtime: FrameworkProfile::GORAVEL_WASM,
            snippets: [],
            consoleAliases: [],
            // content:check lance les tests avec le binaire du navigateur, sous Node : même verdict.
            testModule: '@forelse/runtime-goravel/tests',
        );
    }
}
