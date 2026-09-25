<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Tests;

final class ComposeTest extends SimulatorTestCase
{
    private const NGINX = <<<'CONF'
        server {
            listen 80;
            root /var/www/html/public;
            index index.php;
            location / {
                try_files $uri /index.php$is_args$args;
            }
            location ~ \.php$ {
                fastcgi_pass %s:9000;
                include fastcgi_params;
                fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            }
        }
        CONF;

    private function stack(string $upstream = 'php', bool $phpHasCode = true, string $dbHealth = "    healthcheck:\n      test: [\"CMD-SHELL\", \"pg_isready -U criee\"]\n"): void
    {
        $this->files([
            'public/index.php' => '<?php echo "criée via ".$_SERVER["SERVER_SOFTWARE"]." base ".getenv("DATABASE_HOST")." uri ".$_SERVER["REQUEST_URI"];',
            'docker/nginx.conf' => sprintf(self::NGINX, $upstream),
            'compose.yaml' => "services:\n  web:\n    image: nginx:1.29-alpine\n    ports: [\"8080:80\"]\n    volumes:\n      - ./docker/nginx.conf:/etc/nginx/conf.d/default.conf:ro\n      - ./public:/var/www/html/public:ro\n    depends_on: [php]\n  php:\n    image: php:8.4-fpm-alpine\n    environment:\n      DATABASE_HOST: db\n".($phpHasCode ? "    volumes:\n      - ./public:/var/www/html/public\n" : '')."    depends_on:\n      db:\n        condition: service_healthy\n  db:\n    image: postgres:18-alpine\n    environment:\n      POSTGRES_USER: criee\n      POSTGRES_PASSWORD: \${DB_PASSWORD:-criee}\n    volumes: [db-data:/var/lib/postgresql]\n{$dbHealth}volumes:\n  db-data:\n",
        ]);
    }

    public function testUnComposeYamlMalFormeEstSignaleSansPlanter(): void
    {
        $this->files(['compose.yaml' => "services:\n  app:\n    image: alpine:3.20\n   ports: [80]\n"]);
        [$code, $output] = $this->cli('compose up -d');
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('yaml: line ', $output);
    }

    public function testNginxPhpFpmPostgres(): void
    {
        $this->stack();
        $output = $this->cliOk('compose up -d');
        $this->assertStringContainsString('Container criee-db-1    Healthy', $output);
        // try_files réécrit vers index.php, mais PHP doit voir l'adresse demandée ($request_uri).
        $this->assertSame('criée via nginx/1.29.1 base db uri /une/route', $this->page('localhost:8080/une/route'));
        [$code, $exec] = $this->cli('compose exec php sh -c "nc -z db 5432 && getent hosts web"');
        $this->assertSame(0, $code, $exec);
        $this->assertStringContainsString('web', $exec);
        $down = $this->cliOk('compose down');
        $this->assertStringContainsString('Network criee_default  Removed', $down);
        $this->assertStringContainsString('criee_db-data', $this->cliOk('volume ls'));
    }

    public function testUnNomDeServiceErrone(): void
    {
        $this->stack('app');
        $this->cliOk('compose up -d');
        $this->assertStringContainsString('host not found in upstream "app:9000"', $this->cliOk('compose logs web'));
        $this->assertSame('refused', $this->docker()->http('GET', 'localhost:8080/')->error);
    }

    public function testLeCodeAbsentDuConteneurPhp(): void
    {
        $this->stack(phpHasCode: false);
        $this->cliOk('compose up -d');
        $response = $this->docker()->http('GET', 'localhost:8080/');
        $this->assertSame(404, $response->status);
        $this->assertSame("File not found.\n", $response->body);
    }

    public function testPhpFpmLitSesFichiersParOrdreAlphabetique(): void
    {
        // L'image php-fpm pose « listen = 9000 » dans zz-docker.conf : un fichier qui trie avant
        // se fait écraser, un fichier qui trie après l'emporte (comme le vrai php-fpm).
        $this->stack();
        $this->files([
            'docker/fpm/Dockerfile' => "FROM php:8.4-fpm-alpine\nCOPY loopback.conf /usr/local/etc/php-fpm.d/NOM\n",
            'docker/fpm/loopback.conf' => "[www]\nlisten = 127.0.0.1:9000\n",
        ]);
        $compose = (string) file_get_contents($this->project.'/compose.yaml');
        $this->files(['compose.yaml' => str_replace("    image: php:8.4-fpm-alpine\n", "    build: ./docker/fpm\n", $compose)]);

        foreach (['zz-criee.conf' => 200, 'zzz-criee.conf' => 502] as $nom => $attendu) {
            $dockerfile = str_replace('NOM', $nom, "FROM php:8.4-fpm-alpine\nCOPY loopback.conf /usr/local/etc/php-fpm.d/NOM\n");
            $this->files(['docker/fpm/Dockerfile' => $dockerfile]);
            $this->cliOk('compose down');
            $this->cliOk('compose up -d --build');
            $this->assertSame($attendu, $this->docker()->http('GET', 'localhost:8080/')->status, sprintf('%s : %s', $nom, $attendu === 200 ? 'trie avant zz-docker.conf, sans effet' : 'trie après, php-fpm n\'écoute plus que sur 127.0.0.1'));
        }
        $this->assertStringContainsString('connect() failed (111: Connection refused)', $this->cliOk('compose logs web'));
    }

    public function testNginxSansDaemonOffSArreteAussitot(): void
    {
        $this->stack();
        $compose = (string) file_get_contents($this->project.'/compose.yaml');
        $this->files(['compose.yaml' => str_replace("    image: nginx:1.29-alpine\n", "    image: nginx:1.29-alpine\n    command: [\"nginx\"]\n", $compose)]);
        $this->cliOk('compose up -d');
        $web = $this->docker()->store->findContainer(basename($this->project).'-web-1');
        $this->assertNotNull($web);
        $this->assertFalse($web->isRunning(), 'nginx passe en arrière-plan : le processus n° 1 rend la main et le conteneur s\'arrête.');
        $this->assertSame(0, $web->exitCode);
    }

    /** Remplace la configuration nginx de la pile par $conf (upstream php:9000). */
    private function nginxConf(string $conf): void
    {
        $this->files(['docker/nginx.conf' => $conf]);
    }

    private function servesPhp(string $extra = '', string $tryFiles = 'try_files $uri /index.php$is_args$args;', string $params = "        include fastcgi_params;\n        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n"): string
    {
        return "server {\n    listen 80;\n    root /var/www/html/public;\n    index index.php;\n{$extra}    location / {\n        {$tryFiles}\n    }\n    location ~ \\.php\$ {\n        fastcgi_pass php:9000;\n{$params}    }\n}\n";
    }

    public function testDenyAllRefuseLAcces(): void
    {
        $this->stack();
        $this->files(['public/.htaccess' => "RewriteEngine On\n"]);
        $this->nginxConf($this->servesPhp("    location ~ /\\. {\n        deny all;\n    }\n"));
        $this->cliOk('compose up -d');
        $this->assertSame(403, $this->docker()->http('GET', 'localhost:8080/.htaccess')->status, 'deny all refuse l\'accès aux fichiers cachés.');
        $this->assertSame(200, $this->docker()->http('GET', 'localhost:8080/')->status);
        $this->assertStringContainsString('access forbidden by rule', $this->cliOk('compose logs web'));
    }

    public function testNginxGardeSaConfigurationJusquAuRechargement(): void
    {
        $this->stack();
        $this->cliOk('compose up -d');
        $this->assertSame(200, $this->docker()->http('GET', 'localhost:8080/')->status);

        // Une configuration modifiée sur le disque ne change rien tant que nginx ne la relit pas.
        $this->nginxConf("server {\n    listen 80;\n    return 418 \"changee\";\n}\n");
        $this->assertSame(200, $this->docker()->http('GET', 'localhost:8080/')->status, 'nginx ne relit pas ses fichiers à chaque requête.');

        $sortie = $this->cliOk('compose exec web nginx -s reload');
        $this->assertStringContainsString('signal process started', $sortie);
        $this->assertSame(418, $this->docker()->http('GET', 'localhost:8080/')->status, 'nginx -s reload applique la nouvelle configuration.');

        // Un rechargement refusé garde l'ancienne configuration.
        $this->nginxConf("server {\n    listen 80;\n    retour 200;\n}\n");
        [$code] = $this->cli('compose exec web nginx -s reload');
        $this->assertNotSame(0, $code);
        $this->assertSame(418, $this->docker()->http('GET', 'localhost:8080/')->status);
    }

    public function testIncludeFastcgiConfPoseScriptFilename(): void
    {
        $this->stack();
        $this->nginxConf($this->servesPhp(params: "        include fastcgi.conf;\n"));
        $this->cliOk('compose up -d');
        $this->assertSame(200, $this->docker()->http('GET', 'localhost:8080/')->status, 'fastcgi.conf pose SCRIPT_FILENAME.');
        $this->assertStringContainsString('SCRIPT_FILENAME', $this->cliOk('compose exec web cat /etc/nginx/fastcgi.conf'));

        $this->cliOk('compose down');
        $this->nginxConf($this->servesPhp(params: "        include fastcgi_params;\n"));
        $this->cliOk('compose up -d');
        $this->assertSame("File not found.\n", $this->docker()->http('GET', 'localhost:8080/')->body, 'fastcgi_params ne pose pas SCRIPT_FILENAME.');
    }

    public function testLeReplisDeTryFilesPerdLesArgumentsSansIsArgs(): void
    {
        $this->stack();
        $this->files(['public/index.php' => '<?php echo "lot=".($_GET["lot"] ?? "(aucun)");']);
        $this->nginxConf($this->servesPhp(tryFiles: 'try_files $uri /index.php;'));
        $this->cliOk('compose up -d');
        $this->assertSame('lot=(aucun)', $this->docker()->http('GET', 'localhost:8080/encheres?lot=L-001')->body, 'Sans ?, la redirection interne de try_files perd les arguments.');

        $this->cliOk('compose down');
        $this->nginxConf($this->servesPhp());
        $this->cliOk('compose up -d');
        $this->assertSame('lot=L-001', $this->docker()->http('GET', 'localhost:8080/encheres?lot=L-001')->body);
    }

    public function testNginxMoinsTEnConteneurDePassage(): void
    {
        $this->stack();
        $this->cliOk('compose up -d');
        $sortie = $this->cliOk('compose run --rm web nginx -t');
        $this->assertStringContainsString('syntax is ok', $sortie);
        $this->assertStringContainsString('test is successful', $sortie);
    }

    public function testLaLigneDeLUpstreamIntrouvable(): void
    {
        $this->stack('app');
        $this->nginxConf("server {\n    listen 80;\n    root /var/www/html/public;\n    location ~ \\.php\$ {\n        include fastcgi_params;\n        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n        fastcgi_pass app:9000;\n    }\n}\n");
        $this->cliOk('compose up -d');
        $this->assertStringContainsString('host not found in upstream "app:9000" in /etc/nginx/conf.d/default.conf:7', $this->cliOk('compose logs web'), 'nginx cite la ligne de fastcgi_pass, pas celle de la location.');
    }

    public function testDownArreteDansLOrdreInverseDesDependances(): void
    {
        $this->stack();
        $this->cliOk('compose up -d');
        $sortie = $this->cliOk('compose down');
        $web = strpos($sortie, '-web-1');
        $php = strpos($sortie, '-php-1');
        $db = strpos($sortie, '-db-1');
        $this->assertTrue($web < $php && $php < $db, "web dépend de php, qui dépend de db : ils s'arrêtent dans cet ordre.\n".$sortie);
    }

    public function testPostgres18RefuseLesDonneesALAncienEmplacement(): void
    {
        $this->files(['compose.yaml' => "services:\n  db:\n    image: postgres:18-alpine\n    environment:\n      POSTGRES_PASSWORD: criee\n    volumes:\n      - donnees:/var/lib/postgresql/data\nvolumes:\n  donnees:\n"]);
        // Un volume neuf monté sur …/data : aucune erreur, mais les données partent dans le volume anonyme de l'image.
        $this->cliOk('compose up -d');
        $projet = basename($this->project);
        $this->assertStringNotContainsString('PG_VERSION', $this->cliOk('run --rm -v '.$projet.'_donnees:/v alpine ls /v'), 'PGDATA vaut /var/lib/postgresql/18/docker : le volume monté sur …/data reste vide.');
        $this->cliOk('compose down');
        $this->cliOk('compose up -d');
        $this->assertStringContainsString('init process complete', $this->cliOk('compose logs db'), 'Après down puis up, la base repart de zéro.');

        // Des données d'une ancienne version à cet endroit : l'image refuse de démarrer.
        $this->cliOk('compose down');
        $this->cliOk('run --rm -v '.$projet.'_donnees:/v alpine sh -c "echo 17 > /v/PG_VERSION"');
        $this->cliOk('compose up -d');
        $this->assertStringContainsString('Counter to that, there appears to be PostgreSQL data in:', $this->cliOk('compose logs db'));

        // Le bon montage : /var/lib/postgresql.
        $this->files(['compose.yaml' => "services:\n  db:\n    image: postgres:18-alpine\n    environment:\n      POSTGRES_PASSWORD: criee\n    volumes:\n      - bon:/var/lib/postgresql\nvolumes:\n  bon:\n"]);
        $this->cliOk('compose down');
        $this->cliOk('compose up -d');
        $this->cliOk('compose down');
        $this->cliOk('compose up -d');
        $this->assertStringContainsString('Skipping initialization', $this->cliOk('compose logs db'), 'Monté sur /var/lib/postgresql, le volume garde la base.');
    }

    public function testUnSignalAuProcessusPrincipal(): void
    {
        $this->files(['compose.yaml' => "services:\n  fpm:\n    image: php:8.3-fpm-alpine\n    restart: unless-stopped\n  simple:\n    image: php:8.3-fpm-alpine\n  attente:\n    image: alpine\n    command: sleep infinity\n"]);
        $this->cliOk('compose up -d --wait');
        $projet = basename($this->project);
        $this->cliOk('compose exec fpm kill 1');
        $this->assertTrue($this->docker()->store->findContainer($projet.'-fpm-1')->isRunning(), 'unless-stopped relance php-fpm arrêté par un signal.');
        $this->assertStringContainsString('exiting, bye-bye!', $this->cliOk('compose logs fpm'));
        $this->cliOk('compose exec simple kill 1');
        $this->assertFalse($this->docker()->store->findContainer($projet.'-simple-1')->isRunning(), 'Sans politique de redémarrage, il reste arrêté.');
        $this->cliOk('compose exec attente kill 1');
        $this->cliOk('compose exec attente kill -9 1');
        $this->assertTrue($this->docker()->store->findContainer($projet.'-attente-1')->isRunning(), 'Un sleep sans gestionnaire ignore TERM, et KILL depuis l\'intérieur ne touche pas le processus n° 1.');
    }

    public function testMailpitSaVigieEtUnVolumeDejaExistant(): void
    {
        $this->files(['compose.yaml' => "services:\n  mail:\n    image: axllent/mailpit:v1.21\n    ports: [\"8025:8025\"]\n    volumes: [boite:/data]\nvolumes:\n  boite:\n"]);
        $this->cliOk('volume create '.basename($this->project).'_boite');
        $sortie = $this->cliOk('compose up -d --wait');
        $this->assertStringContainsString('already exists but was not created by Docker Compose', $sortie);
        $this->assertMatchesRegularExpression('/mail-1\s+Healthy/', $sortie, 'Mailpit a sa vigie intégrée (/mailpit readyz).');
        $this->assertSame(200, $this->docker()->http('GET', 'localhost:8025/readyz')->status);
        $this->assertSame(basename($this->project)."-mail-1 running\n", $this->cliOk('compose ps --format "{{.Name}} {{.State}}"'));
    }

    public function testConditionHealthySansHealthcheck(): void
    {
        $this->stack(dbHealth: '');
        [$code, $output] = $this->cli('compose up -d');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('dependency failed to start: container criee-db-1 has no healthcheck configured', $output);
    }

    public function testValidationEtInterpolation(): void
    {
        $this->files(['compose.yaml' => "version: '3.8'\nservices:\n  web:\n    image: nginx\n    port: [\"80:80\"]\n"]);
        [$code, $output] = $this->cli('compose up -d');
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString("services.web additional properties 'port' not allowed", $output);

        $this->files(['compose.yaml' => "services:\n  db:\n    image: postgres:18\n    environment:\n      POSTGRES_PASSWORD: \${DB_PASSWORD}\n"]);
        [, $output] = $this->cli('compose config');
        $this->assertStringContainsString('The "DB_PASSWORD" variable is not set. Defaulting to a blank string.', $output);
        $this->files(['.env' => "DB_PASSWORD=marée\n"]);
        $this->assertStringContainsString('POSTGRES_PASSWORD: marée', $this->cliOk('compose config'));
    }

    public function testLeFichierDeSurchargeSApplique(): void
    {
        $this->files([
            'public/index.php' => '<?php echo "criée ".getenv("APP_ENV");',
            'compose.yaml' => "services:\n  app:\n    image: php:8.3-cli\n    working_dir: /app\n    command: php -S 0.0.0.0:8000 -t public\n    ports: [\"8080:8000\"]\n    environment:\n      APP_ENV: prod\n    volumes:\n      - ./public:/app/public\n",
            'compose.override.yaml' => "services:\n  app:\n    environment:\n      APP_ENV: dev\n    ports: [\"8081:8000\"]\n",
        ]);
        // Sans -f, Compose ajoute de lui-même compose.override.yaml.
        $this->cliOk('compose up -d');
        $this->assertSame('criée dev', $this->page('localhost:8080/'));
        $this->assertSame('criée dev', $this->page('localhost:8081/'), 'Les ports des deux fichiers se cumulent.');

        // Avec -f, seuls les fichiers demandés comptent.
        $this->cliOk('compose down');
        $this->cliOk('compose -f compose.yaml up -d');
        $this->assertSame('criée prod', $this->page('localhost:8080/'));
        $this->assertSame('refused', $this->docker()->http('GET', 'localhost:8081/')->error);
    }

    public function testLesErreursCourantesDUnFichierCompose(): void
    {
        $this->files(['compose.yaml' => "version: '3.8'\nservices:\n  app:\n    image: nginx:1.29-alpine\n"]);
        $this->assertStringContainsString('the attribute `version` is obsolete', $this->cliOk('compose config'), 'Compose prévient que « version » ne sert plus à rien.');

        $this->files(['compose.yaml' => "services:\n  app:\n    image: nginx:1.29-alpine\n    depends_on: [bdd]\n"]);
        [$code, $sortie] = $this->cli('compose up -d');
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('service "app" depends on undefined service "bdd"', $sortie);

        $this->files(['compose.yaml' => "services:\n  a:\n    image: nginx:1.29-alpine\n    ports: [\"8080:80\"]\n  b:\n    image: nginx:1.29-alpine\n    ports: [\"8080:80\"]\n"]);
        [$code, $sortie] = $this->cli('compose up -d');
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('port is already allocated', $sortie, 'Deux services ne publient pas le même port de l\'hôte.');
    }

    public function testEnvironmentLEmporteSurEnvFile(): void
    {
        $this->files([
            'public/index.php' => '<?php echo getenv("PORT_NOM")."/".getenv("CRIEE_URLS");',
            'criee.env' => "PORT_NOM=Port-Inconnu\nCRIEE_URLS=propres\n",
            '.env' => "PORT_NOM=Port-Bigorneau\n",
            'compose.yaml' => "services:\n  app:\n    image: php:8.3-cli\n    working_dir: /app\n    command: php -S 0.0.0.0:8000 -t public\n    ports: [\"8080:8000\"]\n    env_file: [criee.env]\n    environment:\n      PORT_NOM: \${PORT_NOM:-Port-Inconnu}\n    volumes:\n      - ./public:/app/public\n",
        ]);
        $this->cliOk('compose up -d');
        $this->assertSame('Port-Bigorneau/propres', $this->page('localhost:8080/'), 'environment: écrase env_file:, et interpole depuis le .env du projet.');
    }

    public function testLesServicesSeParlentParLeurNom(): void
    {
        $this->files(['compose.yaml' => "services:\n  app:\n    image: php:8.3-apache\n  mail:\n    image: axllent/mailpit:v1.21\n    ports: [\"8025:8025\"]\n"]);
        $this->cliOk('compose up -d');
        $projet = basename($this->project);
        $this->assertStringContainsString('mail', $this->cliOk('exec '.$projet.'-app-1 getent hosts mail'), 'Un service se résout par son nom sur le réseau du projet.');
        $this->assertStringContainsString('200', $this->cliOk('exec '.$projet.'-app-1 curl -s -o /dev/null -w "%{http_code}" http://mail:8025/'));
    }

    public function testUneVariablePoseeDevantLaCommandeLEmporteSurLeEnv(): void
    {
        $this->files([
            'compose.yaml' => "services:\n  app:\n    image: nginx:1.29-alpine\n    environment:\n      APP_ENV: \${APP_ENV:-prod}\n",
            '.env' => "APP_ENV=dev\n",
        ]);
        $this->assertStringContainsString('APP_ENV: dev', $this->cliOk('compose config'));
        $this->assertStringContainsString('APP_ENV: recette', $this->cliOk('APP_ENV=recette compose config'), 'Une variable posée devant la commande l\'emporte sur le .env, comme chez Compose.');

        $this->files(['lancer.sh' => "#!/bin/sh\nAPP_ENV=recette docker compose config\n"]);
        [$code, $sortie] = $this->script('lancer.sh');
        $this->assertSame(0, $code, $sortie);
        $this->assertStringContainsString('$ APP_ENV=recette docker compose config', $sortie, 'Le script réaffiche la commande telle qu\'elle est écrite.');
        $this->assertStringContainsString('APP_ENV: recette', $sortie);
    }

    public function testUnFichierDEnvironnementAbsentEstRefuse(): void
    {
        $this->files(['compose.yaml' => "services:\n  app:\n    image: nginx:1.29-alpine\n"]);
        [$code, $sortie] = $this->cli('compose --env-file absent.env config');
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('env file', $sortie);
        $this->assertStringContainsString('no such file or directory', $sortie);
    }

    public function testPsEnJson(): void
    {
        $this->files(['compose.yaml' => "services:\n  app:\n    image: nginx:1.29-alpine\n    ports: [\"8080:80\"]\n"]);
        $this->cliOk('compose up -d');
        $ligne = trim($this->cliOk('compose ps --format json'));
        $donnees = json_decode($ligne, true);
        $this->assertIsArray($donnees, 'compose ps --format json doit rendre du JSON, une ligne par conteneur : '.$ligne);
        $this->assertSame('app', $donnees['Service']);
        $this->assertSame('running', $donnees['State']);
    }

    public function testUnServiceAProfilAttendSonProfil(): void
    {
        $this->files(['compose.yaml' => "services:\n  app:\n    image: nginx:1.29-alpine\n  outil:\n    image: php:8.3-cli\n    profiles: [outils]\n    command: php -v\n"]);
        $this->cliOk('compose up -d');
        $this->assertNull($this->docker()->store->findContainer('compose-outil-1'), 'Un service à profil ne démarre pas tout seul.');
        $sortie = $this->cliOk('compose --profile outils up -d');
        $this->assertStringContainsString('outil', $sortie);
        $this->assertNotNull($this->docker()->store->findContainer(basename($this->project).'-outil-1'));
    }

    public function testLaConfigurationInchangeeNeRecreePas(): void
    {
        $this->stack();
        $this->cliOk('compose up -d');
        $second = $this->cliOk('compose up -d');
        $this->assertStringContainsString('Container criee-web-1  Running', $second);
        file_put_contents($this->project.'/compose.yaml', str_replace('"8080:80"', '"8081:80"', (string) file_get_contents($this->project.'/compose.yaml')));
        $third = $this->cliOk('compose up -d');
        $this->assertStringContainsString('Container criee-web-1  Recreated', $third);
        $this->assertStringContainsString('criée', $this->page('localhost:8081/'));
    }
}
