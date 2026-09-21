<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Tests;

final class ContainerTest extends SimulatorTestCase
{
    private function apacheApp(string $extra = ''): void
    {
        $this->files([
            'Dockerfile' => "FROM php:8.4-apache\n{$extra}COPY public/ /var/www/html/\n",
            'public/index.php' => '<?php echo "<h1>La Criée</h1><p>".getenv("PORT_NAME")."</p>";',
        ]);
        $this->cliOk('build -t criee .');
    }

    public function testApacheServLePhpDuConteneur(): void
    {
        $this->apacheApp();
        $this->cliOk('run -d -p 8080:80 -e PORT_NAME=Saint-Malo --name criee criee');
        $this->assertStringContainsString('<p>Saint-Malo</p>', $this->page('localhost:8080/'));
        $response = $this->docker()->http('GET', 'localhost:8080/absent');
        $this->assertSame(404, $response->status);
        $this->assertStringContainsString('Apache/2.4.65 (Debian) Server at localhost Port 8080', $response->body);
        $this->assertStringContainsString('"GET /absent HTTP/1.1" 404', $this->cliOk('logs criee'));
        $ps = $this->cliOk('ps');
        $this->assertStringContainsString('0.0.0.0:8080->80/tcp', $ps);
    }

    public function testRienNePublieLePort(): void
    {
        $this->apacheApp();
        $this->cliOk('run -d criee');
        $this->assertSame('refused', $this->docker()->http('GET', 'localhost:8080/')->error);
    }

    public function testMauvaisPortDuConteneur(): void
    {
        $this->apacheApp();
        $this->cliOk('run -d -p 8080:8000 criee');
        $this->assertSame('reset', $this->docker()->http('GET', 'localhost:8080/')->error);
    }

    public function testPortDejaPris(): void
    {
        $this->apacheApp();
        $this->cliOk('run -d -p 8080:80 criee');
        [$code, $output] = $this->cli('run -d -p 8080:80 criee');
        $this->assertSame(125, \intdiv($code, 1) === 125 ? 125 : $code);
        $this->assertStringContainsString('Bind for 0.0.0.0:8080 failed: port is already allocated', $output);
    }

    public function testApacheNonRootEcouteSur80DansUnConteneur(): void
    {
        // Docker Engine (>= 20.10) laisse un utilisateur ordinaire ouvrir les ports sous 1024 dans un conteneur.
        $this->apacheApp("USER www-data\n");
        $this->cliOk('run -d -p 8080:80 --name criee criee');
        $this->assertTrue($this->docker()->store->findContainer('criee')->isRunning(), 'www-data ouvre le port 80 dans un conteneur.');
        $this->assertSame(200, $this->docker()->http('GET', 'localhost:8080/')->status);

        // Sur le réseau de l'hôte, la limite des ports privilégiés revient.
        $this->cliOk('run -d --network host --name hote criee');
        $this->assertStringContainsString('AH00072: make_sock: could not bind to address', $this->cliOk('logs hote'));
    }

    public function testServeurIntegreSurLocalhost(): void
    {
        $this->files(['public/index.php' => '<?php echo "ok";']);
        $this->cliOk('run -d -p 8000:8000 -v ./public:/app -w /app php:8.4-cli php -S localhost:8000');
        $this->assertSame('empty', $this->docker()->http('GET', 'localhost:8000/')->error);
        $this->cliOk('rm -f $(docker ps -q)' === '' ? '' : 'ps');
        $this->cliOk('run -d -p 8001:8000 -v ./public:/app -w /app php:8.4-cli php -S 0.0.0.0:8000');
        $this->assertSame('ok', $this->page('localhost:8001/'));
    }

    public function testUnVolumeMonteMontreLesModifications(): void
    {
        $this->apacheApp();
        $this->cliOk('run -d -p 8080:80 criee');
        $this->cliOk('run -d -p 8081:80 -v ./public:/var/www/html php:8.4-apache');
        file_put_contents($this->project.'/public/index.php', '<?php echo "modifié";');
        $this->assertStringContainsString('La Criée', $this->page('localhost:8080/'));
        $this->assertSame('modifié', $this->page('localhost:8081/'));
    }

    public function testUnVolumeNommeGardeLesDonnees(): void
    {
        $this->files([
            'Dockerfile' => "FROM php:8.4-apache\nRUN mkdir -p /data && chown www-data /data\nCOPY public/ /var/www/html/\n",
            'public/index.php' => '<?php $f = getenv("DATA_DIR")."/visites.txt"; $n = (int) @file_get_contents($f) + 1; file_put_contents($f, (string) $n); echo "visite $n";',
        ]);
        $this->cliOk('build -t compteur .');
        $this->cliOk('run -d -p 8080:80 -e DATA_DIR=/data -v visites:/data --name compteur compteur');
        $this->page('localhost:8080/');
        $this->assertSame('visite 2', $this->page('localhost:8080/'));
        $this->cliOk('rm -f compteur');
        $this->cliOk('run -d -p 8080:80 -e DATA_DIR=/data -v visites:/data --name compteur compteur');
        $this->assertSame('visite 3', $this->page('localhost:8080/'));
        $this->cliOk('rm -f compteur');
        $this->cliOk('run -d -p 8080:80 -e DATA_DIR=/data --name compteur compteur');
        $this->assertSame('visite 1', $this->page('localhost:8080/'), 'Sans volume, les données partent avec le conteneur.');
    }

    public function testUnMontageEnLectureSeuleRefuseLEcriture(): void
    {
        $this->files([
            'Dockerfile' => "FROM php:8.4-apache\nWORKDIR /var/www/html\nCOPY . .\n",
            'src/Vue.php' => "<?php // version de l'image\n",
        ]);
        $this->cliOk('build -t criee .');
        file_put_contents($this->project.'/src/Vue.php', "<?php // version du disque\n");
        $this->cliOk('run -d --name criee -v ./src:/var/www/html/src:ro criee');

        $lu = $this->cliOk('exec criee cat /var/www/html/src/Vue.php');
        $this->assertStringContainsString('version du disque', $lu, 'Un montage de dossier masque ce que l\'image contenait à cet endroit.');

        foreach (['touch /var/www/html/src/nouveau', 'rm /var/www/html/src/Vue.php', 'sh -c "echo x > /var/www/html/src/f"'] as $commande) {
            [$code, $sortie] = $this->cli('exec criee '.$commande);
            $this->assertNotSame(0, $code, sprintf('« %s » devrait échouer sur un montage :ro.', $commande));
            $this->assertStringContainsString('Read-only file system', $sortie, sprintf('« %s » devrait dire que le système de fichiers est en lecture seule.', $commande));
        }

        [$code] = $this->cli('exec criee touch /var/www/html/hors-montage');
        $this->assertSame(0, $code, 'Le :ro ne vaut que pour le dossier monté.');
    }

    public function testUnVolumeGardeSesDroitsDUnConteneurALAutre(): void
    {
        $this->files(['Dockerfile' => "FROM php:8.4-apache\nRUN mkdir -p /var/lib/criee && chown www-data:www-data /var/lib/criee\n"]);
        $this->cliOk('build -t criee .');

        $this->cliOk('run -d --name un -v criee-data:/var/lib/criee criee');
        $this->assertStringContainsString('www-data', $this->cliOk('exec un stat -c "%U" /var/lib/criee'), 'Un volume neuf hérite des droits que l\'image avait à cet endroit.');

        $this->cliOk('exec un chown root:root /var/lib/criee');
        $this->cliOk('rm -f un');
        $this->cliOk('run -d --name deux -v criee-data:/var/lib/criee criee');
        $this->assertStringContainsString('root', $this->cliOk('exec deux stat -c "%U" /var/lib/criee'), 'Un volume déjà peuplé garde ses propres droits : c\'est pourquoi le script d\'entrée doit les reposer.');

        [$code] = $this->cli('volume rm -f criee-data');
        $this->assertSame(1, $code, 'Même avec --force, Docker refuse de supprimer un volume monté.');
        [$code, $sortie] = $this->cli('volume rm -f fantome');
        $this->assertSame(0, $code, '--force ne se plaint pas d\'un volume déjà absent.');
        $this->assertSame('', trim($sortie), 'Un volume absent supprimé avec --force n\'affiche rien.');
    }

    public function testLaVigieSuitLEtatDeLApplication(): void
    {
        $this->files([
            'Dockerfile' => "FROM php:8.4-apache\nCOPY public/ /var/www/html/\nHEALTHCHECK --interval=5s CMD curl -f http://localhost/sante/ || exit 1\n",
            'public/sante/index.php' => '<?php http_response_code(file_exists(__DIR__."/panne") ? 500 : 200); echo "ok";',
        ]);
        $this->cliOk('build -t vigie .');
        $this->cliOk('run -d --name vigie vigie');
        $this->assertStringContainsString('(healthy)', $this->cliOk('ps'));

        // L'application tombe : la vigie le voit à la prochaine observation, sans redémarrer.
        $this->cliOk('exec vigie touch /var/www/html/sante/panne');
        $this->assertStringContainsString('(unhealthy)', $this->cliOk('ps'), 'Un conteneur sain qui tombe en panne devient malade.');
        $this->assertStringContainsString('"FailingStreak": 3', $this->cliOk('inspect vigie'));

        $this->cliOk('exec vigie rm /var/www/html/sante/panne');
        $this->assertStringContainsString('(healthy)', $this->cliOk('ps'), 'Réparé, il redevient sain.');
    }

    public function testLaVigieSePresenteCommeCurlDepuis127001(): void
    {
        $this->files([
            'Dockerfile' => "FROM php:8.4-apache\nCOPY public/ /var/www/html/\n",
            'public/index.php' => '<?php echo $_SERVER["REMOTE_ADDR"]." ".$_SERVER["HTTP_USER_AGENT"];',
        ]);
        $this->cliOk('build -t client .');
        $this->cliOk('run -d --name client client');
        $this->assertSame('127.0.0.1 curl/8.14.1', $this->cliOk('exec client curl -s http://localhost/'));
    }

    public function testChaqueReseauDistribueSesAdressesDepuisDeux(): void
    {
        $this->cliOk('network create quai');
        $this->cliOk('run -d --name un --network quai nginx:1.29-alpine');
        $this->cliOk('run -d --name deux --network quai nginx:1.29-alpine');
        $this->assertSame('172.18.0.2', $this->docker()->store->findContainer('un')->ips['quai']);
        $this->assertSame('172.18.0.3', $this->docker()->store->findContainer('deux')->ips['quai']);
        $this->cliOk('rm -f un');
        $this->cliOk('run -d --name trois --network quai nginx:1.29-alpine');
        $this->assertSame('172.18.0.2', $this->docker()->store->findContainer('trois')->ips['quai'], 'Une adresse libérée est reprise.');
        $this->cliOk('run -d --name ailleurs nginx:1.29-alpine');
        $this->assertSame('172.17.0.2', $this->docker()->store->findContainer('ailleurs')->ips['bridge'], 'Le réseau par défaut a son propre sous-réseau et ses propres adresses.');
    }

    public function testPsMontreLesPortsExposesNonPublies(): void
    {
        $this->cliOk('run -d --name fpm php:8.4-fpm-alpine');
        $this->cliOk('run -d --name web -p 8080:80 nginx:1.29-alpine');
        $ps = $this->cliOk('ps');
        $this->assertStringContainsString('9000/tcp', $ps, 'Un port exposé mais non publié apparaît quand même.');
        $this->assertStringContainsString('0.0.0.0:8080->80/tcp', $ps);
        $lignes = array_values(array_filter(explode("\n", $ps)));
        $this->assertStringContainsString('web', $lignes[1], 'Les conteneurs les plus récents d\'abord.');
    }

    public function testLeCodeDeSortieDUnConteneurPonctuel(): void
    {
        [$code] = $this->cli('run --rm php:8.3-cli-alpine sh -c "exit 4"');
        $this->assertSame(4, $code, 'docker run rend le code de sortie du conteneur, même pour un exit du shell.');
        [$code, $sortie] = $this->cli('run --rm alpine sh -c "false; echo apres"');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('apres', $sortie, 'Après « ; », la commande suivante est lancée même si la précédente échoue.');
        [$code] = $this->cli('run --rm alpine sh -c "false && echo jamais"');
        $this->assertSame(1, $code);

        [$code, $sortie] = $this->cli('run --rm php:8.3-cli-alpine php -r "echo PHP_VERSION; exit(5);"');
        $this->assertSame(5, $code, 'php -r exécute le code pour de vrai, exit() compris.');
        $this->assertStringContainsString('8.3.', $sortie, 'PHP_VERSION est celle du conteneur, pas celle du simulateur.');
    }

    public function testSansRootOnNePeutQueSeRedonnerSesFichiers(): void
    {
        $this->files([
            'Dockerfile' => "FROM php:8.3-fpm-alpine\nRUN mkdir -p /data && chown www-data:www-data /data\nUSER www-data\n",
        ]);
        $this->cliOk('build -t fpm-user .');
        $this->cliOk('run --rm fpm-user chown -R www-data:www-data /data');
        [$code, $sortie] = $this->cli('run --rm fpm-user chown root /data');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('chown: /data: Operation not permitted', $sortie);

        $this->cliOk('run -d --name fpm fpm-user php-fpm');
        $this->assertStringContainsString("'user' directive is ignored when FPM is not running as root", $this->cliOk('logs fpm'));
    }

    public function testNginxSansRootEtSaVarianteUnprivileged(): void
    {
        $this->files(['site.conf' => "server {\n    listen 8080;\n    root /usr/share/nginx/html;\n}\n"]);
        $this->cliOk('run -d --name officiel --user nginx -v ./site.conf:/etc/nginx/conf.d/default.conf:ro nginx:1.29-alpine');
        $officiel = $this->docker()->store->findContainer('officiel');
        $this->assertFalse($officiel->isRunning(), 'nginx officiel sans root ne peut pas créer ses dossiers temporaires.');
        $this->assertStringContainsString('mkdir() "/var/cache/nginx/client_temp" failed (13: Permission denied)', $this->cliOk('logs officiel'));

        $this->cliOk('run -d --name libre -p 8081:8080 nginxinc/nginx-unprivileged:1.29-alpine');
        $this->assertTrue($this->docker()->store->findContainer('libre')->isRunning());
        $this->assertStringContainsString('Welcome to nginx', $this->page('localhost:8081/'));
        $this->assertStringContainsString('nginx', $this->cliOk('exec libre id -un'));
    }

    public function testLesDetailsDUneImageDeProduction(): void
    {
        $this->files(['Dockerfile' => "FROM php:8.3-fpm-alpine\nCOPY --from=composer:2 /usr/bin/composer /usr/bin/composer\n"]);
        $this->cliOk('build -t avec-composer .');
        $this->assertMatchesRegularExpression('/^-rwxr-xr-x .* 3140000 .*composer$/m', $this->cliOk('run --rm avec-composer ls -la /usr/bin/composer'), 'Le binaire de Composer est exécutable et pèse ses 3 Mo.');
        $this->assertStringNotContainsString('__SIM_', $this->cliOk('run --rm avec-composer env'), 'Les variables internes du simulateur restent invisibles.');
        $this->assertStringContainsString('no-debug-non-zts-20230831', $this->cliOk('run --rm avec-composer php-config --extension-dir'), 'PHP 8.3 a son propre numéro d\'API.');
        $this->assertSame("root /usr/bin/composer\nroot /tmp\n", $this->cliOk('run --rm avec-composer stat -c "%U %n" /usr/bin/composer /tmp'));
    }

    public function testUnFichierDonneParNumero(): void
    {
        $this->cliOk('volume create registre');
        $this->cliOk('run --rm -v registre:/d alpine sh -c "mkdir /d/w && chown 82:82 /d/w"');
        $this->cliOk('pull php:8.3-fpm-alpine');
        $this->assertSame("82 www-data\n", $this->cliOk('run --rm --user www-data -v registre:/d php:8.3-fpm-alpine stat -c "%u %U" /d/w'), 'uid 82 est www-data dans l\'image php Alpine.');
        $this->cliOk('run --rm --user www-data -v registre:/d php:8.3-fpm-alpine touch /d/w/ok');
        $this->assertStringContainsString('www-data:x:82:82', $this->cliOk('run --rm php:8.3-fpm-alpine getent passwd www-data'));
        $this->assertStringContainsString('uid=65534(nobody)', $this->cliOk('run --rm php:8.3-fpm-alpine id nobody'));
    }

    public function testUneExceptionNeMontrePasLeSimulateur(): void
    {
        $this->files(['public/index.php' => '<?php function criee() { throw new RuntimeException("marée basse"); } criee();']);
        $this->cliOk('run -d -p 8080:80 -e PHP_INI_SCAN_DIR= -v ./public:/var/www/html php:8.4-apache');
        $corps = $this->docker()->http('GET', 'localhost:8080/')->body;
        $this->assertStringContainsString('{main}', $corps);
        $this->assertStringNotContainsString('PhpExecutor', $corps);
        $this->assertStringNotContainsString('php-exec', $corps);
        [, $sortie] = $this->cli('run --rm php:8.3-cli-alpine php -r "var_dump(extension_loaded(\'intl\'));"');
        $this->assertStringContainsString('bool(false)', $sortie, 'php -r voit les extensions du conteneur, pas celles du simulateur.');
    }

    public function testSauvegarderUnVolumeAvecUnConteneurEphemere(): void
    {
        $this->cliOk('volume create criee-data');
        $this->cliOk('run --rm -v criee-data:/data alpine sh -c "mkdir -p /data && echo enchere > /data/criee.txt"');
        $this->cliOk('run --rm -v criee-data:/data -v ./sauvegardes:/backup alpine sh -c "cp -r /data/. /backup/"');
        $this->assertSame("enchere\n", (string) @file_get_contents($this->project.'/sauvegardes/criee.txt'), 'La sauvegarde doit arriver sur la machine, dans ./sauvegardes.');
        $this->cliOk('volume rm -f criee-data');
        $this->cliOk('volume create criee-data');
        $this->cliOk('run --rm -v criee-data:/data -v ./sauvegardes:/backup alpine sh -c "cp -r /backup/. /data/"');
        [, $sortie] = $this->cli('run --rm -v criee-data:/data alpine cat /data/criee.txt');
        $this->assertStringContainsString('enchere', $sortie, 'La restauration doit remettre le fichier dans le volume.');
    }

    public function testDeuxReseauxIsolentLaBase(): void
    {
        $this->cliOk('network create front');
        $this->cliOk('network create back');
        $this->cliOk('run -d --name db --network back -e POSTGRES_PASSWORD=criee postgres:18-alpine');
        $this->cliOk('run -d --name app --network back alpine sleep infinity');
        $this->cliOk('network connect front app');
        $this->cliOk('run -d --name web --network front alpine sleep infinity');
        [$code] = $this->cli('exec app nc -z db 5432');
        $this->assertSame(0, $code, 'app est sur le réseau back : il joint la base.');
        [$code, $sortie] = $this->cli('exec web nc -z db 5432');
        $this->assertNotSame(0, $code, 'web n\'est pas sur le réseau back : il ne doit pas joindre la base.');
        $this->assertStringContainsString("bad address 'db'", $sortie);
        [$code] = $this->cli('exec web nc -z app 1');
        $this->assertNotSame(0, $code);
        $this->assertNotNull($this->docker()->store->findContainer('web'));
        [, $sortie] = $this->cli('exec web getent hosts app');
        $this->assertStringContainsString('app', $sortie, 'web et app partagent le réseau front : le nom se résout.');
    }

    public function testScriptDEntreeNonExecutable(): void
    {
        $this->files([
            'Dockerfile' => "FROM php:8.4-cli\nCOPY entrypoint.sh /usr/local/bin/\nENTRYPOINT [\"entrypoint.sh\"]\nCMD [\"php\", \"-r\", \"echo 1;\"]\n",
            'entrypoint.sh' => "#!/bin/sh\necho 'préparation'\nexec \"\$@\"\n",
        ]);
        $this->cliOk('build -t app .');
        [$code, $output] = $this->cli('run app');
        $this->assertSame(126, $code);
        $this->assertStringContainsString('exec: "entrypoint.sh": permission denied', $output);

        $this->files(['Dockerfile' => "FROM php:8.4-cli\nCOPY --chmod=755 entrypoint.sh /usr/local/bin/\nENTRYPOINT [\"entrypoint.sh\"]\nCMD [\"sleep\", \"infinity\"]\n"]);
        $this->cliOk('build -t app .');
        $this->cliOk('run -d --name app app');
        $this->assertStringContainsString('préparation', $this->cliOk('logs app'));
        $this->assertStringContainsString('Up', $this->cliOk('ps'));
    }

    public function testFinDeLigneWindows(): void
    {
        $this->files([
            'Dockerfile' => "FROM php:8.4-cli\nCOPY --chmod=755 entrypoint.sh /entrypoint.sh\nENTRYPOINT [\"/entrypoint.sh\"]\n",
            'entrypoint.sh' => "#!/bin/sh\r\necho ok\r\n",
        ]);
        $this->cliOk('build -t app .');
        [$code, $output] = $this->cli('run app');
        $this->assertSame(127, $code);
        $this->assertStringContainsString('no such file or directory', $output);
    }

    public function testLeReseauParDefautNeResoutPasLesNoms(): void
    {
        $this->cliOk('run -d --name db -e POSTGRES_PASSWORD=x postgres:18-alpine');
        $this->cliOk('run -d --name outil alpine sleep infinity');
        [$code, $output] = $this->cli('exec outil nc -z db 5432');
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString("bad address 'db'", $output);

        $this->cliOk('network create criee');
        $this->cliOk('run -d --name db2 --network criee -e POSTGRES_PASSWORD=x postgres:18-alpine');
        $this->cliOk('run -d --name outil2 --network criee alpine sleep infinity');
        [$code] = $this->cli('exec outil2 nc -z db2 5432');
        $this->assertSame(0, $code);
    }

    public function testPostgresSansMotDePasse(): void
    {
        $this->cliOk('run -d --name db postgres:18');
        $this->assertStringContainsString('superuser password is not specified', $this->cliOk('logs db'));
        $this->assertStringContainsString('Exited (1)', $this->cliOk('ps -a'));
    }

    public function testShellAbsentDAlpine(): void
    {
        $this->cliOk('run -d --name outil alpine sleep infinity');
        [$code, $output] = $this->cli('exec -it outil bash');
        $this->assertSame(126, $code);
        $this->assertStringContainsString('exec: "bash": executable file not found in $PATH', $output);
    }
}
