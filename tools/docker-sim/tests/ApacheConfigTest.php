<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Tests;

/** Ce que les chapitres 3 et 7 demandent à l'image php:apache : DocumentRoot, mod_rewrite, php.ini, utilisateur. */
final class ApacheConfigTest extends SimulatorTestCase
{
    private function projet(string $dockerfile, array $extra = []): void
    {
        $this->files([
            'Dockerfile' => $dockerfile,
            'public/index.php' => '<?php $page = $_SERVER["REQUEST_URI"] ?? "/"; echo "<h1>La Criée</h1><p>page=".htmlspecialchars($page)."</p>";',
            'public/.htaccess' => "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteRule ^ index.php [QSA,L]\n</IfModule>\n",
            'src/Secret.php' => '<?php // du code qui ne doit pas être servi',
        ]);
        if ($extra !== []) {
            $this->files($extra);
        }
    }

    private const DOCROOT = <<<'DOCKERFILE'
        FROM php:8.3-apache
        WORKDIR /var/www/html
        ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
        RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
        COPY . .
        DOCKERFILE;

    public function testLeDocumentRootDeplaceCacheLeCode(): void
    {
        $this->projet(self::DOCROOT);
        $this->cliOk('build -t criee .');
        $this->cliOk('run -d -p 8080:80 --name criee criee');
        $this->assertStringContainsString('La Criée', $this->page('localhost:8080/'));
        $this->assertSame(404, $this->docker()->http('GET', 'localhost:8080/src/Secret.php')->status, 'Hors du DocumentRoot, le code source n\'est plus servi.');
    }

    public function testLaReecritureDUrlDemandeModRewrite(): void
    {
        $this->projet(self::DOCROOT);
        $this->cliOk('build -t criee .');
        $this->cliOk('run -d -p 8080:80 --name criee criee');
        // Sans mod_rewrite, le .htaccess est ignoré (il est dans un IfModule) : Apache cherche un fichier.
        $this->assertSame(404, $this->docker()->http('GET', 'localhost:8080/arrivages')->status);

        $this->projet(self::DOCROOT."\nRUN a2enmod rewrite\n");
        $this->cliOk('build -t criee2 .');
        $this->cliOk('run -d -p 8081:80 --name criee2 criee2');
        $reponse = $this->docker()->http('GET', 'localhost:8081/arrivages');
        $this->assertSame(200, $reponse->status, implode(' | ', $reponse->trace));
        $this->assertStringContainsString('page=/arrivages', $reponse->body);
    }

    public function testUnHtaccessSansModRewriteCasseApache(): void
    {
        $this->projet(self::DOCROOT, ['public/.htaccess' => "RewriteEngine On\nRewriteRule ^ index.php [L]\n"]);
        $this->cliOk('build -t criee .');
        $this->cliOk('run -d -p 8080:80 --name criee criee');
        $reponse = $this->docker()->http('GET', 'localhost:8080/');
        $this->assertSame(500, $reponse->status);
        $this->assertStringContainsString("Invalid command 'RewriteEngine'", $this->cliOk('logs criee'));
    }

    public function testPhpIniDeProductionCacheLesErreurs(): void
    {
        $files = ['public/index.php' => '<?php echo "avant"; throw new RuntimeException("la criée a coulé");'];
        $this->projet(self::DOCROOT, $files);
        $this->cliOk('build -t dev .');
        $this->cliOk('run -d -p 8080:80 --name dev dev');
        $this->assertStringContainsString('la criée a coulé', $this->docker()->http('GET', 'localhost:8080/')->body, 'Sans php.ini, les images officielles affichent les erreurs.');

        $this->projet(self::DOCROOT."\nRUN mv \"\$PHP_INI_DIR/php.ini-production\" \"\$PHP_INI_DIR/php.ini\"\n", $files);
        $this->cliOk('build -t prod .');
        $this->cliOk('run -d -p 8081:80 --name prod prod');
        $reponse = $this->docker()->http('GET', 'localhost:8081/');
        $this->assertSame(500, $reponse->status);
        $this->assertStringNotContainsString('la criée a coulé', $reponse->body, 'Avec php.ini-production, display_errors est Off : la page ne raconte plus rien.');
    }

    public function testHealthcheckEtEtatDuConteneur(): void
    {
        $this->projet(self::DOCROOT."\nHEALTHCHECK --interval=10s CMD curl -f http://localhost/ || exit 1\n");
        $this->cliOk('build -t criee .');
        $this->cliOk('run -d -p 8080:80 --name criee criee');
        $this->assertStringContainsString('(healthy)', $this->cliOk('ps'));
        $this->assertSame('healthy', trim($this->cliOk('inspect -f "{{.State.Health.Status}}" criee')));

        $this->projet(self::DOCROOT."\nHEALTHCHECK CMD curl -f http://localhost/absente || exit 1\n");
        $this->cliOk('build -t malade .');
        $this->cliOk('run -d -p 8081:80 --name malade malade');
        $this->assertStringContainsString('(unhealthy)', $this->cliOk('ps'));
    }

    public function testArgEtTarget(): void
    {
        $this->files([
            'Dockerfile' => "FROM php:8.3-apache AS base\nARG APP_ENV=prod\nENV APP_ENV=\${APP_ENV}\nCOPY public/ /var/www/html/\n\nFROM base AS dev\nENV APP_ENV=dev\nRUN a2enmod rewrite\n",
            'public/index.php' => '<?php echo "env=".getenv("APP_ENV");',
        ]);
        $prod = $this->docker()->build('.', null, ['criee:prod'], 'base');
        $this->assertTrue($prod->success, $prod->output);
        $this->assertSame('prod', $prod->image->config->env['APP_ENV']);
        $dev = $this->docker()->build('.', null, ['criee:dev'], 'dev', ['APP_ENV' => 'staging']);
        $this->assertSame('dev', $dev->image->config->env['APP_ENV']);
        $this->assertContains('rewrite', $dev->image->apacheModules);
        $avecArg = $this->docker()->build('.', null, ['criee:staging'], 'base', ['APP_ENV' => 'staging']);
        $this->assertSame('staging', $avecArg->image->config->env['APP_ENV']);
        $this->cliOk('run -d -p 8080:80 criee:staging');
        $this->assertStringContainsString('env=staging', $this->page('localhost:8080/'));
    }

    public function testReglagesDeConfDEtScriptDEntree(): void
    {
        $this->files([
            'Dockerfile' => <<<'DOCKERFILE'
                FROM php:8.3-apache
                WORKDIR /var/www/html
                ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
                RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
                RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
                COPY docker/php.ini "$PHP_INI_DIR/conf.d/criee.ini"
                RUN a2enmod rewrite
                COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/docker-entrypoint-criee
                ENTRYPOINT ["docker-entrypoint-criee"]
                CMD ["apache2-foreground"]
                HEALTHCHECK --interval=10s CMD curl -f http://localhost/sante || exit 1
                COPY . .
                DOCKERFILE,
            'docker/php.ini' => "expose_php = Off\nmemory_limit = 256M\n",
            'docker/entrypoint.sh' => "#!/bin/sh\nset -e\necho 'Criée : préparation du registre'\nmkdir -p /var/www/html/var\nchown -R www-data:www-data /var/www/html/var\nexec \"\$@\"\n",
            'public/index.php' => '<?php if (($_SERVER["REQUEST_URI"] ?? "/") === "/sante") { header("Content-Type: text/plain"); echo "OK"; return; } echo "<h1>La Criée</h1>";',
            'public/.htaccess' => "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteRule ^ index.php [QSA,L]\n</IfModule>\n",
        ]);
        $this->cliOk('build -t criee .');
        $this->cliOk('run -d -p 8080:80 --name criee criee');
        $conteneur = $this->docker()->store->findContainer('criee');
        $this->assertTrue($conteneur->isRunning(), implode("\n", $conteneur->logs));
        $this->assertStringContainsString('Criée : préparation du registre', $this->cliOk('logs criee'), 'Le script d\'entrée doit s\'exécuter avant le serveur.');
        $this->assertStringContainsString('(healthy)', $this->cliOk('ps'));
        $this->assertSame("www-data\n", $this->cliOk('exec criee stat -c "%U" /var/www/html/var'));
        $this->assertStringContainsString('memory_limit = 256M', $this->cliOk('exec criee cat /usr/local/etc/php/conf.d/criee.ini'), 'Une destination entre guillemets doit être copiée comme les autres.');
        $reponse = $this->docker()->http('GET', 'localhost:8080/');
        $this->assertSame(200, $reponse->status);
        $this->assertArrayNotHasKey('X-Powered-By', $reponse->headers, 'expose_php = Off, même dans conf.d/, retire l\'en-tête X-Powered-By.');
        $this->assertStringContainsString('PHP Version => 8.3.', $this->cliOk('run --rm criee php -i'), '« php -i » dans un conteneur affiche la configuration, ce n\'est pas un shell interactif.');
    }

    public function testApacheNonRootSurUnPortNonPrivilegie(): void
    {
        $this->projet(<<<'DOCKERFILE'
            FROM php:8.3-apache
            WORKDIR /var/www/html
            ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
            RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
            RUN sed -ri -e 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
             && sed -ri -e 's/:80>/:8080>/' /etc/apache2/sites-available/000-default.conf
            COPY --chown=www-data:www-data . .
            USER www-data
            EXPOSE 8080
            DOCKERFILE);
        $this->cliOk('build -t criee .');
        $this->cliOk('run -d -p 8081:8080 --name criee criee');
        $conteneur = $this->docker()->store->findContainer('criee');
        $this->assertTrue($conteneur->isRunning(), implode("\n", $conteneur->logs));
        $this->assertSame([8080], $conteneur->listening);
        $this->assertStringContainsString('La Criée', $this->page('localhost:8081/'));
        $this->assertSame("www-data\n", $this->cliOk('exec criee whoami'));
    }

    public function testUtilisateurNonRootEtPhpFpm(): void
    {
        $this->files([
            'Dockerfile' => "FROM php:8.3-fpm\nWORKDIR /var/www/html\nCOPY --chown=www-data:www-data public/ public/\nUSER www-data\n",
            'public/index.php' => '<?php echo "ok";',
        ]);
        $this->cliOk('build -t app .');
        $this->cliOk('run -d --name app app');
        $conteneur = $this->docker()->store->findContainer('app');
        $this->assertTrue($conteneur->isRunning(), implode("\n", $conteneur->logs));
        $this->assertSame([9000], $conteneur->listening, 'php-fpm écoute sur 9000, un port non privilégié : pas besoin d\'être root.');
        [$code, $sortie] = $this->cli('exec app whoami');
        $this->assertSame(0, $code);
        $this->assertSame("www-data\n", $sortie);
        [, $sortie] = $this->cli('exec app touch /etc/interdit');
        $this->assertStringContainsString('Permission denied', $sortie);
    }
}
