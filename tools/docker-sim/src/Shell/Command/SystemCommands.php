<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell\Command;

use Forelse\DockerSim\Fs\MemoryFs;
use Forelse\DockerSim\Http\Nginx\NginxConfig;
use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\Shell\Result;
use Forelse\DockerSim\State\Blob;

/** Utilisateurs et groupes, modules Apache, test de configuration nginx, Node.js et quelques utilitaires. */
final class SystemCommands implements Command
{
    public function names(): array
    {
        return ['useradd', 'adduser', 'addgroup', 'groupadd', 'usermod', 'groupmod', 'deluser', 'userdel',
            'a2enmod', 'a2dismod', 'a2ensite', 'a2dissite', 'a2enconf', 'a2disconf', 'apache2ctl', 'apachectl',
            'nginx', 'npm', 'npx', 'yarn', 'node', 'corepack',
            'update-ca-certificates', 'locale-gen', 'setfacl', 'getfacl', 'crontab', 'supervisorctl', 'install'];
    }

    public function run(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        return match ($name) {
            'useradd', 'adduser' => $this->addUser($name, $args, $m),
            'addgroup', 'groupadd', 'groupmod' => $m->isRoot() ? Result::ok() : Result::error(1, "{$name}: Permission denied\n"),
            'usermod' => $this->usermod($args, $m),
            'deluser', 'userdel' => $this->deleteUser($args, $m),
            'a2enmod', 'a2dismod', 'a2ensite', 'a2dissite', 'a2enconf', 'a2disconf' => $this->apacheToggle($name, $args, $m),
            'apache2ctl', 'apachectl' => $this->apacheCtl($args, $m),
            'nginx' => $this->nginx($args, $m),
            'npm', 'yarn', 'npx', 'corepack' => $this->npm($name, $args, $m),
            'node' => Result::ok(\in_array('-v', $args, true) || \in_array('--version', $args, true) ? "v24.8.0\n" : ''),
            'update-ca-certificates' => Result::ok("Updating certificates in /etc/ssl/certs...\n0 added, 0 removed; done.\n", 0.4),
            'locale-gen' => Result::ok("Generating locales (this might take a while)...\nGeneration complete.\n", 2.0),
            'setfacl', 'getfacl', 'crontab', 'supervisorctl' => Result::ok(),
            'install' => $this->install($args, $m),
            default => Result::ok(),
        };
    }

    private function addUser(string $name, array $args, Machine $m): Result
    {
        if (!$m->isRoot()) {
            return Result::error(1, "{$name}: Permission denied.\n{$name}: cannot lock /etc/passwd; try again later.\n");
        }
        $uid = null;
        $home = true;
        $user = null;
        $noHomeFlags = ['-H', '--no-create-home', '-M'];
        for ($i = 0; $i < \count($args); ++$i) {
            $arg = $args[$i];
            if (\in_array($arg, ['-u', '--uid'], true)) {
                $uid = (int) ($args[++$i] ?? 1000);
            } elseif (\in_array($arg, ['-G', '-g', '--gid', '--ingroup', '-s', '--shell', '-h', '--home', '-d', '--home-dir', '-c', '--comment', '--gecos'], true)) {
                ++$i;
            } elseif (\in_array($arg, $noHomeFlags, true)) {
                $home = false;
            } elseif (preg_match('/^-[A-Za-z]+$/', $arg)) {
                if (str_contains($arg, 'H')) {
                    $home = false;
                }
                continue;
            } elseif (!str_starts_with($arg, '-')) {
                $user ??= $arg;
            }
        }
        // useradd (Debian) ne crée le dossier personnel qu'avec -m.
        if ($name === 'useradd' && !\in_array('-m', $args, true) && !\in_array('--create-home', $args, true)) {
            $home = false;
        }
        if ($user === null) {
            return Result::error(2, "Usage: {$name} [options] LOGIN\n");
        }
        if (isset($m->facts->users[$user])) {
            return Result::error($name === 'useradd' ? 9 : 1, $name === 'useradd' ? "useradd: user '{$user}' already exists\n" : "adduser: user '{$user}' in use\n");
        }
        $uid ??= max(999, ...array_values($m->facts->users)) + 1;
        if (\in_array($uid, $m->facts->users, true)) {
            return Result::error(4, "{$name}: UID {$uid} is not unique\n");
        }
        $m->facts->users[$user] = $uid;
        $passwd = (string) $m->fs->read('/etc/passwd');
        $m->fs->write('/etc/passwd', $passwd.sprintf("%s:x:%d:%d::/home/%s:/bin/sh\n", $user, $uid, $uid, $user));
        if ($home) {
            $m->fs->mkdir('/home/'.$user, $user);
        }

        return Result::ok('', 0.2);
    }

    private function usermod(array $args, Machine $m): Result
    {
        if (!$m->isRoot()) {
            return Result::error(1, "usermod: Permission denied.\n");
        }
        $user = end($args) ?: '';
        if (!isset($m->facts->users[$user])) {
            return Result::error(6, "usermod: user '{$user}' does not exist\n");
        }
        $position = array_search('-u', $args, true);
        if ($position !== false) {
            $m->facts->users[$user] = (int) ($args[$position + 1] ?? $m->facts->users[$user]);
        }

        return Result::ok();
    }

    private function deleteUser(array $args, Machine $m): Result
    {
        $user = end($args) ?: '';
        unset($m->facts->users[$user]);

        return Result::ok();
    }

    private function apacheToggle(string $name, array $args, Machine $m): Result
    {
        if (!$m->facts->hasBinary('apache2')) {
            return Result::error(127, "/bin/sh: 1: {$name}: not found\n");
        }
        $kind = match (true) { str_contains($name, 'mod') => 'mods', str_contains($name, 'site') => 'sites', default => 'conf' };
        $enable = str_starts_with($name, 'a2en');
        $out = '';
        foreach (array_filter($args, static fn ($a) => $a[0] !== '-') as $item) {
            if ($kind === 'mods') {
                $known = ['rewrite', 'headers', 'expires', 'deflate', 'ssl', 'proxy', 'proxy_http', 'proxy_fcgi', 'remoteip', 'setenvif', 'socache_shmcb', 'http2', 'mpm_event', 'mpm_prefork'];
                if (!\in_array($item, $known, true)) {
                    return Result::error(1, $out."ERROR: Module {$item} does not exist!\n");
                }
                if ($enable) {
                    if (\in_array($item, $m->facts->apacheModules, true)) {
                        $out .= "Module {$item} already enabled\n";
                        continue;
                    }
                    $m->facts->apacheModules[] = $item;
                    $m->fs->write('/etc/apache2/mods-enabled/'.$item.'.load', "LoadModule {$item}_module /usr/lib/apache2/modules/mod_{$item}.so\n");
                    $out .= "Enabling module {$item}.\nTo activate the new configuration, you need to run:\n  service apache2 restart\n";
                } else {
                    $m->facts->apacheModules = array_values(array_diff($m->facts->apacheModules, [$item]));
                    $m->fs->delete('/etc/apache2/mods-enabled/'.$item.'.load');
                    $out .= "Module {$item} disabled.\n";
                }
                continue;
            }
            $item = preg_replace('/\.conf$/', '', $item) ?? $item;
            $available = '/etc/apache2/'.$kind.'-available/'.$item.'.conf';
            $enabled = '/etc/apache2/'.$kind.'-enabled/'.$item.'.conf';
            if ($enable) {
                if (!$m->fs->isFile($available)) {
                    return Result::error(1, $out.sprintf("ERROR: %s %s does not exist!\n", $kind === 'sites' ? 'Site' : 'Conf', $item));
                }
                $m->fs->write($enabled, (string) $m->fs->read($available));
                $out .= sprintf("Enabling %s %s.\nTo activate the new configuration, you need to run:\n  service apache2 reload\n", $kind === 'sites' ? 'site' : 'conf', $item);
            } else {
                $m->fs->delete($enabled);
                $out .= sprintf("%s %s disabled.\n", $kind === 'sites' ? 'Site' : 'Conf', $item);
            }
        }

        return Result::ok($out, 0.2);
    }

    private function apacheCtl(array $args, Machine $m): Result
    {
        if (\in_array('-M', $args, true)) {
            $out = "Loaded Modules:\n core_module (static)\n so_module (static)\n mpm_prefork_module (shared)\n php_module (shared)\n";
            foreach ($m->facts->apacheModules as $module) {
                $out .= " {$module}_module (shared)\n";
            }

            return Result::ok($out);
        }
        if (\in_array('configtest', $args, true) || \in_array('-t', $args, true)) {
            return Result::ok("Syntax OK\n");
        }

        return Result::ok();
    }

    private function nginx(array $args, Machine $m): Result
    {
        if (\in_array('-v', $args, true) || \in_array('-V', $args, true)) {
            return Result::ok("nginx version: nginx/1.29.1\n");
        }
        if (\in_array('-t', $args, true) || \in_array('-T', $args, true)) {
            $problem = NginxConfig::load($m->fs, $m->network)->problem();
            if ($problem !== null) {
                return Result::error(1, "nginx: [emerg] {$problem}\nnginx: configuration file /etc/nginx/nginx.conf test failed\n");
            }

            return Result::ok("nginx: the configuration file /etc/nginx/nginx.conf syntax is ok\nnginx: configuration file /etc/nginx/nginx.conf test is successful\n");
        }
        if (\in_array('-s', $args, true) && \in_array('reload', $args, true)) {
            $problem = NginxConfig::load($m->fs, $m->network)->problem();
            if ($problem !== null) {
                // nginx refuse la nouvelle configuration et garde l'ancienne.
                return Result::error(1, "nginx: [emerg] {$problem}\n");
            }
            $m->signals[] = 'nginx-reload';

            return new Result(0, '', sprintf("%s [notice] 45#45: signal process started\n", gmdate('Y/m/d H:i:s')));
        }

        return Result::ok();
    }

    private function npm(string $name, array $args, Machine $m): Result
    {
        $sub = $args[0] ?? '';
        if (\in_array($sub, ['-v', '--version'], true)) {
            return Result::ok($name === 'yarn' ? "1.22.22\n" : "11.6.0\n");
        }
        $package = $m->fs->read($m->path('package.json'));
        if (\in_array($sub, ['ci', 'install', 'i', ''], true) && $name !== 'npx') {
            if ($package === null) {
                return Result::error(254, "npm error code ENOENT\nnpm error syscall open\nnpm error path {$m->cwd}/package.json\nnpm error errno -2\nnpm error enoent Could not read package.json: Error: ENOENT: no such file or directory, open '{$m->cwd}/package.json'\n");
            }
            if ($sub === 'ci' && !$m->fs->isFile($m->path('package-lock.json'))) {
                return Result::error(1, "npm error code EUSAGE\nnpm error\nnpm error The `npm ci` command can only install with an existing package-lock.json or\nnpm error npm-shrinkwrap.json with lockfileVersion >= 1.\n");
            }
            $data = json_decode($package, true) ?: [];
            $count = \count($data['dependencies'] ?? []) + \count($data['devDependencies'] ?? []);
            if ($m->fs instanceof MemoryFs) {
                $m->fs->putBlob($m->path('node_modules/.package-lock.json'), Blob::virtual(max(1, $count) * 12_000_000));
            } else {
                $m->fs->write($m->path('node_modules/.package-lock.json'), '{}');
            }

            return Result::ok(sprintf("\nadded %d packages, and audited %d packages in 9s\n\nfound 0 vulnerabilities\n", $count * 40, $count * 40 + 1), 9.0 + $count);
        }
        if ($sub === 'run' || $name === 'npx') {
            $script = $args[1] ?? '';
            if (!$m->fs->isDir($m->path('node_modules'))) {
                return Result::error(127, "sh: 1: vite: not found\n");
            }
            $m->fs->write($m->path('public/build/manifest.json'), "{\n  \"assets/app.js\": {\"file\": \"assets/app-3f2a1c.js\"}\n}\n");
            $m->fs->write($m->path('public/build/assets/app-3f2a1c.js'), "console.log('app');\n");

            return Result::ok("\n> build\n> vite build\n\nvite v7.1.5 building for production...\n✓ 12 modules transformed.\npublic/build/manifest.json  0.12 kB\n✓ built in 1.20s\n", 4.0);
        }

        return Result::ok();
    }

    private function install(array $args, Machine $m): Result
    {
        $operands = array_values(array_filter($args, static fn ($a) => $a[0] !== '-'));
        $mode = null;
        $position = array_search('-m', $args, true);
        if ($position !== false) {
            $mode = octdec($args[$position + 1] ?? '0755');
            $operands = array_values(array_filter($operands, static fn ($o) => $o !== ($args[$position + 1] ?? null)));
        }
        if (\in_array('-d', $args, true)) {
            foreach ($operands as $dir) {
                $m->fs->mkdir($m->path($dir));
            }

            return Result::ok();
        }
        if (\count($operands) >= 2) {
            $source = $m->path($operands[0]);
            $target = $m->path($operands[1]);
            if ($m->fs->isDir($target)) {
                $target .= '/'.basename($source);
            }
            $m->fs->write($target, (string) $m->fs->read($source), $mode ?? 0755);
        }

        return Result::ok();
    }
}
