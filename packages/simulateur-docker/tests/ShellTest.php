<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Tests;

use Forelse\DockerSim\Fs\MemoryFs;
use Forelse\DockerSim\Shell\Facts;
use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Shell\Machine;
use PHPUnit\Framework\TestCase;

final class ShellTest extends TestCase
{
    private function machine(string $os = 'debian'): Machine
    {
        return new Machine(new MemoryFs(), new Facts($os, 'shell', null, [], [], [], ['sh', 'echo', 'cat', 'mkdir', 'rm', 'ls', 'sed', 'grep', 'test', 'touch', 'chmod', 'chown', 'id', 'whoami', 'env', 'printf', 'tr', 'head', 'wc']), ['PATH' => '/usr/bin:/bin', 'NAME' => 'Criée'], '/');
    }

    private function sh(string $script, ?Machine $machine = null): array
    {
        $machine ??= $this->machine();
        $code = Interpreter::create()->run($script, $machine);

        return [$code, $machine->output, $machine];
    }

    /** Relevé sur alpine:3.20 : « id -u -n » et « id -un » donnent le nom, « id -u » le numéro. */
    public function testIdDonneLeNomAvecUEtN(): void
    {
        $this->assertSame("root\n", $this->sh('id -u -n')[1]);
        $this->assertSame("root\n", $this->sh('id -un')[1]);
        $this->assertSame("0\n", $this->sh('id -u')[1]);
    }

    public function testGrepCommeLeVrai(): void
    {
        $m = $this->machine();
        $m->fs->write('/etc/fpm/www.conf', "[www]\nuser = www-data\nlisten = 127.0.0.1:9000\n");
        $m->fs->write('/etc/fpm/zz-docker.conf', "[global]\ndaemonize = no\n\n[www]\nlisten = 9000\n");

        [, $sortie] = $this->sh('grep -n "^listen" /etc/fpm/zz-docker.conf', $m);
        $this->assertSame("5:listen = 9000\n", $sortie, '-n numérote les lignes.');

        [, $sortie] = $this->sh('grep "^listen\|^daemonize" /etc/fpm/zz-docker.conf', $this->with($m));
        $this->assertSame("daemonize = no\nlisten = 9000\n", $sortie, 'En BRE, \| est l\'alternative.');

        [, $sortie] = $this->sh('grep "a|b" /etc/fpm/www.conf', $this->with($m));
        $this->assertSame('', $sortie, 'En BRE, | est un caractère ordinaire.');

        [, $sortie] = $this->sh('grep -n listen /etc/fpm/*.conf', $this->with($m));
        $this->assertSame("/etc/fpm/www.conf:3:listen = 127.0.0.1:9000\n/etc/fpm/zz-docker.conf:5:listen = 9000\n", $sortie, 'Plusieurs fichiers : nom, numéro, ligne.');

        [, $sortie] = $this->sh('grep -c listen /etc/fpm/*.conf', $this->with($m));
        $this->assertSame("/etc/fpm/www.conf:1\n/etc/fpm/zz-docker.conf:1\n", $sortie);

        [$code, $sortie] = $this->sh('grep -E "^(user|daemonize)" /etc/fpm/www.conf /etc/absent', $this->with($m));
        $this->assertSame(2, $code, 'Un fichier manquant fait sortir grep en 2.');
        $this->assertStringContainsString("/etc/fpm/www.conf:user = www-data\n", $sortie);
        $this->assertStringContainsString('grep: /etc/absent: No such file or directory', $sortie);
    }

    public function testLsDUnMotifAfficheLesChemins(): void
    {
        $m = $this->machine();
        $m->fs->write('/etc/fpm/www.conf', "x\n");
        $m->fs->write('/etc/fpm/zz-docker.conf', "x\n");
        [, $sortie] = $this->sh('ls /etc/fpm/*.conf', $m);
        $this->assertSame("/etc/fpm/www.conf\n/etc/fpm/zz-docker.conf\n", $sortie, 'ls affiche les fichiers tels qu\'on les a nommés.');
    }

    public function testDebianParleGnuAlpineParleBusybox(): void
    {
        [, $gnu] = $this->sh('rm /absent; mkdir /a/b; touch /x/y; ls /absent', $this->machine('debian'));
        $this->assertStringContainsString("rm: cannot remove '/absent': No such file or directory", $gnu);
        $this->assertStringContainsString("mkdir: cannot create directory '/a/b': No such file or directory", $gnu);
        $this->assertStringContainsString("touch: cannot touch '/x/y': No such file or directory", $gnu);
        $this->assertStringContainsString("ls: cannot access '/absent': No such file or directory", $gnu);

        [, $busybox] = $this->sh('rm /absent', $this->machine('alpine'));
        $this->assertStringContainsString("rm: can't remove '/absent': No such file or directory", $busybox);
    }

    /** Une machine neuve qui partage le système de fichiers de $m. */
    private function with(Machine $m): Machine
    {
        return new Machine($m->fs, $m->facts, $m->env, '/');
    }

    public function testLsAvecSesOptions(): void
    {
        $m = $this->machine();
        $m->fs->write('/d/a/f.txt', "x\n");
        $m->fs->write('/d/.cache', "x\n");
        [, $sortie] = $this->sh('ls -a /d', $m);
        $this->assertSame(".\n..\n.cache\na\n", $sortie);
        [, $sortie] = $this->sh('ls -R /d', $this->with($m));
        $this->assertSame("/d:\na\n\n/d/a:\nf.txt\n", $sortie);
    }

    public function testListesEtVariables(): void
    {
        [$code, $output] = $this->sh('echo "bonjour $NAME" && false || echo "rattrapé ${ABSENT:-défaut}"; echo fin');
        $this->assertSame(0, $code);
        $this->assertSame("bonjour Criée\nrattrapé défaut\nfin\n", $output);
    }

    public function testSetEArreteLeScript(): void
    {
        [$code, $output] = $this->sh("set -e\necho un\nfalse\necho deux");
        $this->assertSame(1, $code);
        $this->assertSame("un\n", $output);
    }

    public function testCommandeIntrouvable(): void
    {
        [$code, $output] = $this->sh('bash -c "echo"');
        $this->assertSame(127, $code);
        $this->assertSame("/bin/sh: 1: bash: not found\n", $output);
        [, $output] = $this->sh('curl http://x', $this->machine('alpine'));
        $this->assertSame("/bin/sh: curl: not found\n", $output);
    }

    public function testRedirectionsSedEtStructures(): void
    {
        [$code, $output, $machine] = $this->sh(<<<'SH'
            mkdir -p /etc/app
            printf 'root=/var/www/html\n' > /etc/app/site.conf
            sed -i 's!/var/www/html!/var/www/html/public!g' /etc/app/site.conf
            for f in a b; do echo "$f" >> /tmp/liste; done
            if [ -f /etc/app/site.conf ]; then cat /etc/app/site.conf; else echo absent; fi
            cat /tmp/liste | wc -l
            SH);
        $this->assertSame(0, $code, $output);
        $this->assertSame("root=/var/www/html/public\n2\n", $output);
        $this->assertSame("a\nb\n", $machine->fs->read('/tmp/liste'));
    }

    public function testUnUtilisateurNonRootNEcritPasPartout(): void
    {
        $machine = $this->machine();
        $machine->user = 'www-data';
        $machine->facts->users['www-data'] = 33;
        [$code, $output] = $this->sh('touch /etc/interdit', $machine);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Permission denied', $output);
    }

    /** Un nom annoncé par names() mais oublié dans le match ne doit pas réussir en silence. */
    public function testChaqueCommandeDeclareeAUneImplementation(): void
    {
        $shell = Interpreter::create();
        $this->assertNotEmpty($shell->commands());
        foreach ($shell->commands() as $name => $command) {
            try {
                $command->run($name, [], $this->machine(), '', $shell);
            } catch (\LogicException $e) {
                $this->fail($e->getMessage());
            } catch (\Throwable) {
                // Sans arguments, une commande peut échouer autrement : seule l'implémentation compte ici.
            }
        }
    }

    /** Une arborescence pour find : /z/a.php, /z/b.txt, /z/sub/c.php, /z/sub/d.txt, /x/e.php. */
    private function arbre(string $os = 'debian'): Machine
    {
        $m = new Machine(new MemoryFs(), new Facts($os, 'shell', null, [], [], [], ['sh', 'find', 'echo', 'sed', 'cat', 'ls']), ['PATH' => '/usr/bin:/bin'], '/');
        foreach (['/z/a.php', '/z/b.txt', '/z/sub/c.php', '/z/sub/d.txt', '/x/e.php'] as $fichier) {
            $m->fs->write($fichier, 'x');
        }

        return $m;
    }

    /** @return list<string> les fichiers restants, triés */
    private function restants(Machine $m): array
    {
        $fichiers = [];
        foreach (['/z', '/x'] as $racine) {
            [, $sortie] = $this->sh("find {$racine} -type f", $this->with($m));
            array_push($fichiers, ...array_filter(explode("\n", $sortie)));
        }
        sort($fichiers);

        return $fichiers;
    }

    /** -path et « * » qui traverse les « / » : seul le contenu de sub/ part, pas tout /z. */
    public function testFindPathNeSupprimeQueCeQuIlDesigne(): void
    {
        $m = $this->arbre();
        [$code] = $this->sh("find /z -path '*/sub/*' -delete", $m);

        $this->assertSame(0, $code);
        $this->assertSame(['/x/e.php', '/z/a.php', '/z/b.txt'], $this->restants($m));
    }

    /** « ! » (ou -not) inverse le test : a.php reste, le reste des fichiers part. */
    public function testFindNegationGardeCeQuIlExclut(): void
    {
        $m = $this->arbre();
        $this->sh('find /z ! -name a.php -type f -delete', $m);
        $this->assertSame(['/x/e.php', '/z/a.php'], $this->restants($m));

        $m = $this->arbre();
        $this->sh('find /z -type f -not -name "*.php" -delete', $m);
        $this->assertSame(['/x/e.php', '/z/a.php', '/z/sub/c.php'], $this->restants($m));
    }

    public function testFindParcourtChaquePointDeDepartEtSesOperateurs(): void
    {
        [, $sortie] = $this->sh("find /x /z -name '*.php'", $this->arbre());
        $this->assertSame("/x/e.php\n/z/a.php\n/z/sub/c.php\n", $sortie);

        [, $sortie] = $this->sh('find /z -mindepth 1 -maxdepth 1 -type d', $this->arbre());
        $this->assertSame("/z/sub\n", $sortie);

        [, $sortie] = $this->sh("find /z -name 'b*' -o -name 'c*'", $this->arbre());
        $this->assertSame("/z/b.txt\n/z/sub/c.php\n", $sortie);

        [, $sortie] = $this->sh("find /z \\( -name 'a*' -o -name 'd*' \\) -type f", $this->arbre());
        $this->assertSame("/z/a.php\n/z/sub/d.txt\n", $sortie);

        [, $sortie] = $this->sh('find /z -name "*.php" -exec echo {} +', $this->arbre());
        $this->assertSame("/z/a.php /z/sub/c.php\n", $sortie, 'Avec « + », une seule commande pour tous les chemins.');

        $m = $this->arbre();
        $m->cwd = '/z';
        [, $sortie] = $this->sh('find sub -type f', $m);
        $this->assertSame("sub/c.php\nsub/d.txt\n", $sortie, 'Un chemin de départ relatif est affiché tel quel.');
    }

    /**
     * Un prédicat inconnu est refusé avant tout parcours : rien n'est supprimé.
     * Messages de busybox (alpine) et de GNU findutils (debian).
     */
    public function testFindRefuseUnPredicatInconnuSansRienSupprimer(): void
    {
        foreach (['alpine' => "find: unrecognized: -newer\n", 'debian' => "find: unknown predicate `-newer'\n"] as $os => $message) {
            $m = $this->arbre($os);
            [$code, $sortie] = $this->sh('find /z -newer /x/e.php -delete', $m);

            $this->assertSame(1, $code, $os);
            $this->assertSame($message, $sortie, $os);
            $this->assertSame(['/x/e.php', '/z/a.php', '/z/b.txt', '/z/sub/c.php', '/z/sub/d.txt'], $this->restants($m), $os);
        }
    }

    /** -delete sur un dossier encore plein échoue, comme le vrai : il ne vide pas son contenu. */
    public function testFindDeleteNeVideJamaisUnDossierPlein(): void
    {
        $m = $this->arbre();
        [$code, $sortie] = $this->sh('find /z -name sub -delete', $m);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("cannot delete '/z/sub': Directory not empty", $sortie);
        $this->assertContains('/z/sub/c.php', $this->restants($m));
    }
}
