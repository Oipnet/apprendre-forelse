<?php

namespace App\Content\Framework\Profiles;

use App\Content\Framework\FrameworkProfile;
use App\Content\Framework\FrameworkProfileProvider;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * PHP sans framework : public/index.php sert l'aperçu, la console lance un script comme « php script.php »
 * dans un terminal (voir playground/src/runtime/php-cli.php), et Composer ne fait que charger les classes.
 */
final class PhpProfile implements FrameworkProfileProvider
{
    public function profile(): FrameworkProfile
    {
        return new FrameworkProfile(
            id: 'php',
            label: 'PHP',
            order: 50,
            console: 'php',
            consoleExample: 'bin/bonjour.php',
            bootNote: new TranslatableMessage('playground.framework.php.demarrage', [], 'apprentissage'),
            unpackLabel: new TranslatableMessage('playground.framework.php.decompression', [], 'apprentissage'),
            testRunner: FrameworkProfile::PHPUNIT,
            // Un projet sans framework ne suit la version d'aucun paquet : « version: » n'y a pas de sens.
            versionPackage: null,
            projectDirs: ['public', 'src', 'bin', 'templates', 'tests'],
            codeDirs: ['public/', 'src/', 'bin/', 'templates/'],
            cacheDirs: [],
            testCaches: [],
            // .forelse/ : le chargeur des paquets ajoutés (bouton « Paquets »), pas du code de l'apprenant.
            hidden: ['vendor', '.git', 'node_modules', '.phpunit.cache', '.forelse'],
            namespaceRoots: ['src' => 'App', 'tests' => 'App\\Tests'],
            lessonLanguages: '```php, ```bash',
            drafting: PhpWasm::DRAFTING.<<<'TEXTE'
                Conventions de ce projet (PHP 8.4 sans framework), à respecter même si tu connais d'autres façons de faire :
                - public/index.php est le contrôleur frontal : il charge vendor/autoload.php et route avec un `match`
                  sur le chemin de l'URL. Les classes vivent dans src/ (espace de noms App\, chargé par Composer).
                - Les vues sont des fichiers PHP de templates/, et toute valeur affichée passe par htmlspecialchars().
                - La console lance un script : `php bin/script.php arguments`, `$argv` comme dans un terminal.
                TEXTE,
            runtime: FrameworkProfile::PHP_WASM,
            snippets: ['php'],
            // « php bin/bonjour.php » et « bin/bonjour.php » mènent au même script.
            consoleAliases: ['php' => ''],
        );
    }
}
