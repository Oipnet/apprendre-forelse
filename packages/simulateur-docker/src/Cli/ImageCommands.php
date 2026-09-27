<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Cli;

use Forelse\DockerSim\Catalog\Catalog;
use Forelse\DockerSim\Catalog\UnknownImageException;
use Forelse\DockerSim\Engine\Docker;
use Forelse\DockerSim\Engine\DockerException;
use Forelse\DockerSim\State\Image;
use Forelse\DockerSim\State\Store;

/** docker build, images, image …, rmi, pull, push, tag, history, search. */
final class ImageCommands implements CliCommand
{
    private Output $out;

    public function __construct(private readonly Docker $docker, private readonly InspectCommand $inspect)
    {
    }

    public function names(): array
    {
        return ['build', 'buildx', 'builder', 'images', 'image', 'rmi', 'pull', 'push', 'tag', 'history', 'search'];
    }

    public function run(string $name, array $argv, Output $out): int
    {
        $this->out = $out;

        return match ($name) {
            'build' => $this->build($argv),
            'buildx', 'builder' => ($argv[0] ?? '') === 'build' ? $this->build(\array_slice($argv, 1)) : Application::unknown($out, 'buildx '.($argv[0] ?? '')),
            'images' => $this->images($argv),
            'image' => $this->image($argv),
            'rmi' => $this->rmi($argv),
            'pull' => $this->pull($argv),
            'push' => $this->push($argv),
            'tag' => $this->tag($argv),
            'history' => $this->history($argv),
            'search' => $this->search($argv),
            default => throw new \LogicException(sprintf('%s : « %s » est déclarée sans implémentation.', self::class, $name)),
        };
    }

    /** @param list<string> $argv */
    private function build(array $argv): int
    {
        $args = Args::parse($argv, [
            't' => ['tag', true, true], 'tag' => ['tag', true, true], 'f' => ['file', true], 'file' => ['file', true],
            'target' => ['target', true], 'build-arg' => ['build-arg', true, true], 'no-cache' => ['no-cache', false],
            'progress' => ['progress', true], 'q' => ['quiet', false], 'quiet' => ['quiet', false], 'pull' => ['pull', false],
            'load' => ['load', false], 'platform' => ['platform', true], 'label' => ['label', true, true], 'network' => ['network', true],
            'secret' => ['secret', true, true], 'ssh' => ['ssh', true], 'cache-from' => ['cache-from', true, true], 'rm' => ['rm', false],
        ], 'build');
        if (\count($args->positional) !== 1) {
            throw new UsageError(sprintf("'docker buildx build' requires 1 argument\n\nUsage:  docker buildx build [OPTIONS] PATH | URL | -\n\nRun 'docker buildx build --help' for more information"), 1);
        }
        foreach ($args->all('tag') as $tag) {
            if (preg_match('/[A-Z]/', explode(':', $tag)[0]) || !preg_match('#^[a-zA-Z0-9][a-zA-Z0-9._/-]*(:[\w.-]+)?$#', $tag)) {
                $this->out->line(sprintf('ERROR: failed to build: invalid tag "%s": repository name must be lowercase', $tag));

                return 1;
            }
        }
        $buildArgs = [];
        foreach ($args->all('build-arg') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $buildArgs[$key] = $value ?? '';
        }
        $result = $this->docker->build($args->positional[0], $args->get('file'), $args->all('tag'), $args->get('target'), $buildArgs, $args->has('no-cache'));
        if ($args->has('quiet') && $result->success) {
            $this->out->line($result->image->id ?? '');
        } else {
            $this->out->write($result->output);
        }
        if ($result->notes !== []) {
            $this->out->line('');
            foreach ($result->notes as $note) {
                $this->out->line('💡 '.$note);
            }
        }
        if ($result->success && $args->all('tag') === []) {
            $this->out->line('');
            $this->out->line('What\'s next:');
            $this->out->line('    L\'image n\'a pas de nom : ajoutez -t nom pour la retrouver dans docker images.');
        }

        return $result->success ? 0 : 1;
    }

    /** @param list<string> $argv */
    private function images(array $argv): int
    {
        $args = Args::parse($argv, ['a' => ['all', false], 'all' => ['all', false], 'q' => ['quiet', false], 'quiet' => ['quiet', false], 'filter' => ['filter', true, true], 'f' => ['filter', true, true], 'no-trunc' => ['no-trunc', false], 'format' => ['format', true], 'digests' => ['digests', false]], 'images');
        $danglingOnly = \in_array('dangling=true', $args->all('filter'), true);
        $rows = [];
        $images = $this->docker->images();
        uasort($images, static fn (Image $a, Image $b) => $b->createdAt <=> $a->createdAt);
        foreach ($images as $image) {
            $tags = $image->tags === [] ? ['<none>:<none>'] : $image->tags;
            foreach ($tags as $tag) {
                if ($danglingOnly && $tag !== '<none>:<none>') {
                    continue;
                }
                $separator = strrpos($tag, ':');
                $rows[] = [substr($tag, 0, $separator), substr($tag, $separator + 1), $args->has('no-trunc') ? $image->id : $image->shortId(), Format::ago($image->createdAt), Format::size($image->size())];
            }
        }
        if ($args->has('quiet')) {
            $this->out->write(implode("\n", array_unique(array_column($rows, 2))).($rows !== [] ? "\n" : ''));

            return 0;
        }
        $filter = $args->positional[0] ?? null;
        if ($filter !== null) {
            $rows = array_values(array_filter($rows, static fn ($r) => $r[0] === $filter || $r[0].':'.$r[1] === $filter));
        }
        $this->out->write(Format::table(['REPOSITORY', 'TAG', 'IMAGE ID', 'CREATED', 'SIZE'], $rows));

        return 0;
    }

    /** @param list<string> $argv */
    private function image(array $argv): int
    {
        $sub = array_shift($argv);

        return match ($sub) {
            'ls', 'list' => $this->images($argv),
            'rm', 'remove' => $this->rmi($argv),
            'pull' => $this->pull($argv),
            'push' => $this->push($argv),
            'build' => $this->build($argv),
            'tag' => $this->tag($argv),
            'history' => $this->history($argv),
            'inspect' => $this->inspect->inspect($argv, 'image', $this->out),
            'prune' => $this->imagePrune($argv),
            default => Application::unknown($this->out, 'image '.($sub ?? '')),
        };
    }

    /** @param list<string> $argv */
    private function rmi(array $argv): int
    {
        $args = Args::parse($argv, ['f' => ['force', false], 'force' => ['force', false], 'no-prune' => ['no-prune', false]], 'rmi');
        $code = 0;
        foreach ($args->positional as $reference) {
            $image = $this->docker->findImage($reference);
            if ($image === null) {
                $this->out->line(sprintf('Error response from daemon: No such image: %s', str_contains($reference, ':') ? $reference : $reference.':latest'));
                $code = 1;
                continue;
            }
            foreach ($this->docker->containers() as $container) {
                if ($container->imageId === $image->id && !$args->has('force')) {
                    $this->out->line(sprintf('Error response from daemon: conflict: unable to delete %s (%s) - image is being used by %s container %s', $reference, $container->isRunning() ? 'cannot be forced' : 'must be forced', $container->isRunning() ? 'running' : 'stopped', $container->shortId()));
                    $code = 1;
                    continue 2;
                }
            }
            $normalized = Store::normalizeTag($reference);
            if (\count($image->tags) > 1 && \in_array($normalized, $image->tags, true)) {
                $this->docker->untag($image, $normalized);
                $this->out->line('Untagged: '.$normalized);
                continue;
            }
            foreach ($image->tags as $tag) {
                $this->out->line('Untagged: '.$tag);
            }
            $this->docker->removeImage($image);
            $this->out->line('Deleted: '.$image->id);
        }

        return $code;
    }

    /** @param list<string> $argv */
    private function imagePrune(array $argv): int
    {
        $args = Args::parse($argv, ['a' => ['all', false], 'all' => ['all', false], 'f' => ['force', false], 'force' => ['force', false], 'filter' => ['filter', true, true]], 'image prune');
        $deleted = $this->docker->pruneImages($args->has('all'));
        $reclaimed = array_sum(array_map(static fn (Image $i) => $i->size(), $deleted));
        if (!$args->has('force')) {
            $this->out->line('WARNING! This will remove all '.($args->has('all') ? 'images without at least one container associated to them' : 'dangling images').'.');
        }
        if ($deleted !== []) {
            $this->out->line('Deleted Images:');
            foreach ($deleted as $image) {
                foreach ($image->tags as $tag) {
                    $this->out->line('untagged: '.$tag);
                }
                $this->out->line('deleted: '.$image->id);
            }
            $this->out->line('');
        }
        $this->out->line('Total reclaimed space: '.Format::size($reclaimed));

        return 0;
    }

    /** @param list<string> $argv */
    private function pull(array $argv): int
    {
        $args = Args::parse($argv, ['q' => ['quiet', false], 'quiet' => ['quiet', false], 'a' => ['all', false], 'platform' => ['platform', true]], 'pull');
        $reference = $args->positional[0] ?? throw new UsageError("\"docker pull\" requires exactly 1 argument.\nSee 'docker pull --help'.\n\nUsage:  docker pull [OPTIONS] NAME[:TAG|@DIGEST]\n\nPull an image or a repository from a registry", 1);
        try {
            [, $output] = $this->docker->pull($reference);
            $this->out->write($args->has('quiet') ? explode("\n", trim($output))[\count(explode("\n", trim($output))) - 1]."\n" : $output);

            return 0;
        } catch (UnknownImageException $e) {
            [$repository, $tag] = Catalog::split($reference);
            $this->out->line(str_contains($e->getMessage(), 'manifest') ? sprintf('Error response from daemon: manifest for %s:%s not found: manifest unknown: manifest unknown', $repository, $tag) : 'Error response from daemon: '.$e->getMessage());

            return 1;
        }
    }

    /** @param list<string> $argv */
    private function push(array $argv): int
    {
        $reference = $argv[0] ?? '';
        $repository = str_contains($reference, '/') ? explode(':', $reference)[0] : 'library/'.explode(':', $reference)[0];
        if ($this->docker->findImage($reference) === null) {
            $this->out->line(sprintf("The push refers to repository [docker.io/%s]\nAn image does not exist locally with the tag: %s", $repository, $reference));
        } else {
            $this->out->line(sprintf("The push refers to repository [docker.io/%s]\npush access denied, repository does not exist or may require authorization: server message: insufficient_scope: authorization failed", $repository));
            $this->out->line('💡 Le simulateur n\'a pas de registre : docker push ne peut pas publier l\'image.');
        }

        return 1;
    }

    /** @param list<string> $argv */
    private function tag(array $argv): int
    {
        if (\count($argv) !== 2) {
            throw new UsageError("\"docker tag\" requires exactly 2 arguments.\nSee 'docker tag --help'.\n\nUsage:  docker tag SOURCE_IMAGE[:TAG] TARGET_IMAGE[:TAG]", 1);
        }
        $image = $this->docker->findImage($argv[0]) ?? throw new DockerException(sprintf('No such image: %s', Store::normalizeTag($argv[0])), 1);
        $this->docker->tag($image, $argv[1]);

        return 0;
    }

    /** @param list<string> $argv */
    private function history(array $argv): int
    {
        $args = Args::parse($argv, ['no-trunc' => ['no-trunc', false], 'H' => ['human', false], 'human' => ['human', false], 'q' => ['quiet', false]], 'history');
        $image = $this->docker->findImage($args->positional[0] ?? '') ?? throw new DockerException(sprintf('No such image: %s', $args->positional[0] ?? ''), 1);
        $rows = [];
        foreach (array_reverse($image->layers) as $index => $layer) {
            $created = $layer->createdBy;
            if (!$args->has('no-trunc') && mb_strlen($created) > 45) {
                $created = mb_substr($created, 0, 44).'…';
            }
            $rows[] = [$index === 0 ? $image->shortId() : '<missing>', Format::ago($layer->createdAt ?: $image->createdAt), $created, $layer->empty ? '0B' : Format::size($layer->size), 'buildkit.dockerfile.v0']; // toutes les couches viennent de BuildKit, instructions sans contenu comprises
        }
        $this->out->write(Format::table(['IMAGE', 'CREATED', 'CREATED BY', 'SIZE', 'COMMENT'], $rows));

        return 0;
    }

    /** @param list<string> $argv */
    private function search(array $argv): int
    {
        $term = $argv[0] ?? '';
        $rows = [];
        foreach (['php' => 'While designed for web development, the PHP scripting language also provides general-purpose use.', 'composer' => 'Composer is a dependency manager written in and for PHP.', 'nginx' => 'Official build of Nginx.', 'postgres' => 'The PostgreSQL object-relational database system provides reliability and data integrity.', 'mysql' => 'MySQL is a widely used, open-source relational database management system (RDBMS).', 'mariadb' => 'MariaDB Server is a high performing open source relational database, forked from MySQL.', 'redis' => 'Redis is the world’s fastest data platform for caching, vector search, and NoSQL databases.', 'node' => 'Node.js is a JavaScript-based platform for server-side and networking applications.', 'alpine' => 'A minimal Docker image based on Alpine Linux with a complete package index and only 5 MB in size!', 'debian' => 'Debian is a Linux distribution that\'s composed entirely of free and open-source software.', 'axllent/mailpit' => 'An email and SMTP testing tool with API for developers', 'adminer' => 'Database management in a single PHP file.', 'caddy' => 'Caddy 2 is a powerful, enterprise-ready, open source web server with automatic HTTPS written in Go.', 'dunglas/frankenphp' => 'The modern PHP app server'] as $name => $description) {
            if ($term === '' || str_contains($name, strtolower($term))) {
                $rows[] = [$name, mb_strlen($description) > 44 ? mb_substr($description, 0, 44).'…' : $description, (string) (1000 + crc32($name) % 9000), str_contains($name, '/') ? '' : '[OK]'];
            }
        }
        $this->out->write(Format::table(['NAME', 'DESCRIPTION', 'STARS', 'OFFICIAL'], $rows));

        return 0;
    }
}
