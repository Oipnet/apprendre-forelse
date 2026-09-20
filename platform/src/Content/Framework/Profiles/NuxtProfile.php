<?php

namespace App\Content\Framework\Profiles;

use App\Content\Framework\FrameworkProfile;
use App\Content\Framework\FrameworkProfileProvider;

/**
 * Nuxt, simulé en TypeScript par packages/simulateur-nuxt : le seul framework sans PHP. Ses tests sont des tests
 * Vitest, lancés sous Node par content:check et dans le worker du simulateur par le navigateur.
 */
final class NuxtProfile implements FrameworkProfileProvider
{
    public function profile(): FrameworkProfile
    {
        return new FrameworkProfile(
            id: 'nuxt',
            label: 'Nuxt',
            order: 40,
            console: 'npx nuxi',
            consoleExample: 'info',
            bootNote: 'Nuxt est simulé dans votre navigateur : vos pages sont rendues côté serveur puis hydratées, comme avec nuxi dev.',
            unpackLabel: 'Décompression du projet',
            testRunner: FrameworkProfile::VITEST,
            // La version de Nuxt vient de package.json, pas d'un composer.lock : « version: » n'est pas géré.
            versionPackage: null,
            projectDirs: ['app', 'server', 'shared', 'tests', 'public'],
            codeDirs: ['app/', 'server/', 'shared/'],
            cacheDirs: [],
            testCaches: [],
            hidden: ['node_modules', '.nuxt', '.output', '.git'],
            // Pas de PHP : aucun fichier créé dans l'explorateur ne reçoit de namespace.
            namespaceRoots: [],
            lessonLanguages: '```vue, ```ts, ```bash',
            // L'atelier ne sait pas encore échafauder ni faire rédiger un exercice Nuxt : voir ExerciseDrafter.
            drafting: null,
            // Pas de PHP : c'est le simulateur Nuxt qui joue le projet, dans son propre worker.
            runtime: FrameworkProfile::NUXT_SIM,
            snippets: [],
            consoleAliases: [],
            // content:check lance les mêmes tests que le navigateur, par le même simulateur. Le moteur
            // ne sait pas où ce paquet est installé : il demande à Node de le résoudre.
            testModule: '@forelse/simulateur-nuxt/tests',
        );
    }
}
