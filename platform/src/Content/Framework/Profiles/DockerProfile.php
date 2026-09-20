<?php

namespace App\Content\Framework\Profiles;

use App\Content\Framework\FrameworkProfile;
use App\Content\Framework\FrameworkProfileProvider;

/**
 * Docker, simulé en PHP pur par tools/docker-sim : images, conteneurs et Compose, et le PHP servi par
 * les conteneurs s'exécute pour de vrai. Pour la rédaction, « le code » comprend l'infrastructure.
 */
final class DockerProfile implements FrameworkProfileProvider
{
    public function profile(): FrameworkProfile
    {
        return new FrameworkProfile(
            id: 'docker',
            label: 'Docker',
            order: 30,
            console: 'docker',
            consoleExample: 'compose up -d',
            bootNote: 'Docker est simulé dans votre navigateur : images, conteneurs et Compose, et le PHP de vos conteneurs s\'exécute pour de vrai en WebAssembly.',
            unpackLabel: 'Décompression du projet',
            testRunner: FrameworkProfile::PHPUNIT,
            // Un projet Docker ne suit la version d'aucun framework : « version: » n'y a pas de sens.
            versionPackage: null,
            // docker cp, un volume monté sur le projet : ce qu'un conteneur écrit chez l'hôte reste visible.
            projectDirs: ['public', 'src', 'docker', 'config', 'templates', 'data'],
            codeDirs: ['Dockerfile', 'compose.', 'docker-compose.', '.dockerignore', 'docker/', 'public/', 'src/', '.env'],
            cacheDirs: [],
            testCaches: [],
            hidden: ['vendor', '.git', 'node_modules', '.phpunit.cache'],
            namespaceRoots: ['src' => 'App', 'tests' => 'App\\Tests'],
            lessonLanguages: '```dockerfile, ```yaml, ```bash, ```nginx, ```php',
            drafting: <<<'TEXTE'
                Conventions de ce projet (Docker simulé), à respecter même si tu connais d'autres façons de faire :
                - Docker ne tourne pas vraiment : un simulateur en PHP (Forelse\DockerSim) construit les images,
                  lance les conteneurs et exécute pour de vrai le PHP qu'ils servent (Apache, nginx + php-fpm,
                  php -S). Catalogue d'images fermé : php (cli, fpm, apache, alpine), composer, nginx, postgres,
                  mysql, mariadb, redis, node, alpine, debian, caddy, axllent/mailpit, adminer. Les paquets apk/apt
                  et les extensions PHP (docker-php-ext-install, pecl) sont simulés avec leurs dépendances.
                - Les fichiers de l'apprenant : Dockerfile, compose.yaml, .dockerignore, .env, docker/ (configuration
                  nginx, php.ini, scripts d'entrée), et la petite application PHP (public/, src/).
                - Les tests cachés étendent `Forelse\DockerSim\Testing\DockerTestCase` : `$this->build('tag')`
                  (BuildResult : success, output, image, steps, warnings), `$this->dockerOk('run -d -p 8080:80 tag')`
                  ou `$this->dockerOk('compose up -d')` (n'importe quelle commande docker), `$this->http('localhost:8080/')`
                  (HttpResponse : status, body, error, trace), `$this->container('nom')`, `$this->service('web')`,
                  `$this->image('tag')`, `$this->exec($conteneur, 'commande shell')`, `$this->dockerfile()` et
                  `$this->instructions('COPY')` pour lire le Dockerfile, `$this->compose()` pour le projet compose.
                  Assertions : assertBuildSucceeded, assertImageHasFile, assertImageLacksFile, assertImageSizeBelow,
                  assertRunning, assertPageContains. Chaque test part d'un démon vierge.
                - Le code PHP servi par les conteneurs s'exécute dans le processus des tests : pas d'exit(), pas de
                  fonction globale déclarée dans public/index.php (utilise des classes autoloadées).
                - `preview:` est l'adresse visitée dans l'aperçu : `/localhost:8080/`. `setup:` liste des commandes
                  docker (`build -t criee .`) jouées au chargement.
                - Documentation : https://docs.docker.com/reference/dockerfile/, https://docs.docker.com/reference/compose-file/,
                  https://hub.docker.com/_/php.
                TEXTE,
        );
    }
}
