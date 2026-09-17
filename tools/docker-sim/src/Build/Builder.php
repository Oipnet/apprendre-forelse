<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Build;

use Forelse\DockerSim\Catalog\Catalog;
use Forelse\DockerSim\Catalog\UnknownImageException;
use Forelse\DockerSim\Dockerfile\Dockerfile;
use Forelse\DockerSim\Dockerfile\Instruction;
use Forelse\DockerSim\Dockerfile\ParseError;
use Forelse\DockerSim\Dockerfile\Parser;
use Forelse\DockerSim\Dockerfile\Stage;
use Forelse\DockerSim\Engine\ImageFactory;
use Forelse\DockerSim\Fs\MemoryFs;
use Forelse\DockerSim\Fs\Path;
use Forelse\DockerSim\Shell\Facts;
use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\State\Blob;
use Forelse\DockerSim\State\Image;
use Forelse\DockerSim\State\ImageConfig;
use Forelse\DockerSim\State\Layer;
use Forelse\DockerSim\State\Store;

/**
 * docker build, à la manière de BuildKit : étapes numérotées, cache par couche (une instruction dont
 * la clé n'a pas changé est « CACHED »), étapes multiples, --target, --build-arg, avertissements
 * des « build checks », et messages d'erreur avec l'extrait du Dockerfile.
 */
final class Builder
{
    private BuildOutput $out;
    /** @var array<string, array{fs: MemoryFs, facts: Facts, config: ImageConfig, layers: list<Layer>, key: string, base: string, kind: string, os: string, phpVersion: ?string, docroot: ?string}> */
    private array $stages = [];
    /** @var list<array{name: string, instruction: string, cached: bool, seconds: float, line: int, output: string}> */
    private array $steps = [];
    /** @var list<string> */
    private array $warnings = [];
    /** @var list<string> */
    private array $notes = [];
    private string $dockerfileText = '';

    public function __construct(
        private readonly Store $store,
        private readonly Catalog $catalog = new Catalog(),
        private ?Interpreter $shell = null,
    ) {
        $this->shell ??= Interpreter::create();
    }

    /**
     * @param list<string>          $tags
     * @param array<string,string>  $buildArgs
     */
    public function build(string $contextDir, ?string $dockerfile = null, array $tags = [], ?string $target = null, array $buildArgs = [], bool $noCache = false, string $dockerfileLabel = 'Dockerfile'): BuildResult
    {
        $this->out = new BuildOutput();
        $this->stages = [];
        $this->steps = [];
        $this->warnings = [];
        $this->notes = [];
        $dockerfile ??= rtrim($contextDir, '/').'/Dockerfile';
        $this->out->line('#0 building with "default" instance using docker driver');
        $this->out->blank();

        if (!is_dir($contextDir)) {
            return $this->fail(sprintf('ERROR: unable to prepare context: path "%s" not found', $contextDir), withStep: false);
        }
        $step = $this->out->step('[internal] load build definition from '.basename($dockerfileLabel));
        if (!is_file($dockerfile)) {
            $this->out->stepLine($step, 'transferring dockerfile: 2B done');
            $this->out->stepError($step, 'failed to read dockerfile: open '.basename($dockerfileLabel).': no such file or directory');

            return $this->fail('ERROR: failed to build: failed to solve: failed to read dockerfile: open '.basename($dockerfileLabel).': no such file or directory');
        }
        $this->dockerfileText = (string) file_get_contents($dockerfile);
        $this->out->stepLine($step, sprintf('transferring dockerfile: %s done', BuildOutput::bytes(\strlen($this->dockerfileText))));
        $this->out->stepDone($step, 0.0);

        try {
            $parsed = (new Parser())->parse($this->dockerfileText);
        } catch (ParseError $e) {
            $detail = $e->getMessage();
            if (preg_match('/unknown instruction: (\S+)/', $detail, $m)) {
                $suggestion = $this->suggestInstruction($m[1]);
                if ($suggestion !== null) {
                    $detail .= sprintf(' (did you mean %s?)', strtolower($suggestion));
                }
            }

            return $this->fail("ERROR: failed to build: failed to solve: {$detail}", excerptLine: $e->dockerfileLine);
        }
        $this->lint($parsed);

        // Étapes à construire : la cible et ce dont elle dépend.
        $targetStage = $target !== null ? $parsed->stage($target) : $parsed->lastStage();
        if ($targetStage === null) {
            return $this->fail(sprintf('ERROR: failed to build: failed to solve: target stage "%s" could not be found', $target));
        }
        $globalArgs = $this->globalArgs($parsed, $buildArgs);
        $needed = $this->neededStages($parsed, $targetStage, $globalArgs);

        // Métadonnées des images de base.
        $bases = [];
        foreach ($needed as $stage) {
            $reference = Variables::expand($stage->from->words()[0], $globalArgs);
            if ($parsed->stage($reference) !== null && $parsed->stage($reference)->index < $stage->index) {
                continue;
            }
            $bases[$reference] ??= $stage->from->line;
        }
        foreach ($this->copyFromImages($needed, $parsed, $globalArgs) as $reference => $line) {
            $bases[$reference] ??= $line;
        }
        $resolved = [];
        foreach ($bases as $reference => $line) {
            [$repository, $tag] = Catalog::split($reference);
            $canonical = Catalog::canonical($repository, $tag);
            $step = $this->out->step('[internal] load metadata for '.$canonical);
            try {
                $resolved[$reference] = $this->catalog->resolve($reference);
                $this->out->stepDone($step, 0.8 + (crc32($reference) % 70) / 100);
            } catch (UnknownImageException $e) {
                $message = str_contains($e->getMessage(), 'pull access denied')
                    ? sprintf('%s: failed to resolve source metadata for %s: pull access denied, repository does not exist or may require authorization: server message: insufficient_scope: authorization failed', $reference, $canonical)
                    : sprintf('%s: failed to resolve source metadata for %s: %s: not found', $reference, $canonical, $canonical);
                $this->out->stepError($step, $canonical.': not found');
                $this->out->errorBlock('[internal] load metadata for '.$canonical, '');

                return $this->fail('ERROR: failed to build: failed to solve: '.$message, excerptLine: $line);
            }
        }

        $context = new BuildContext($contextDir, $dockerfile);
        $step = $this->out->step('[internal] load .dockerignore');
        $ignoreFile = is_file($dockerfile.'.dockerignore') ? $dockerfile.'.dockerignore' : rtrim($contextDir, '/').'/.dockerignore';
        $this->out->stepLine($step, sprintf('transferring context: %s done', BuildOutput::bytes(is_file($ignoreFile) ? (int) filesize($ignoreFile) : 2)));
        $this->out->stepDone($step, 0.0);

        $needsContext = false;
        foreach ($needed as $stage) {
            foreach ($stage->instructions as $instruction) {
                if (\in_array($instruction->name, ['COPY', 'ADD'], true) && !isset($instruction->flags['from']) && $instruction->heredoc === null) {
                    $needsContext = true;
                }
            }
        }
        $contextSize = 0;
        if ($needsContext) {
            $step = $this->out->step('[internal] load build context');
            $contextSize = $context->size();
            $this->out->stepLine($step, sprintf('transferring context: %s %.1fs done', BuildOutput::bytes($contextSize), min(9.0, $contextSize / 40_000_000)));
            $this->out->stepDone($step, min(9.0, $contextSize / 40_000_000));
        }

        $multiStage = \count($parsed->stages) > 1;
        $usedArgs = [];
        foreach ($needed as $stage) {
            $error = $this->buildStage($stage, $parsed, $resolved, $globalArgs, $buildArgs, $usedArgs, $context, $noCache, $multiStage);
            if ($error !== null) {
                return $error;
            }
        }

        $final = $this->stages[$targetStage->label()];
        $unused = array_diff(array_keys($buildArgs), array_keys($usedArgs), ['HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY', 'http_proxy', 'https_proxy', 'no_proxy', 'BUILDKIT_INLINE_CACHE']);
        $image = $this->exportImage($final, $tags, $targetStage);

        $step = $this->out->step('exporting to image');
        $this->out->stepLine($step, 'exporting layers 0.1s done');
        $this->out->stepLine($step, 'writing image '.$image->id.' done');
        foreach ($tags as $tag) {
            $this->out->stepLine($step, 'naming to docker.io/library/'.Store::normalizeTag($tag).' done');
        }
        $this->out->stepDone($step, 0.2);

        if ($unused !== []) {
            $this->out->line(sprintf(' 1 warning found: [Warning] One or more build-args [%s] were not consumed', implode(' ', $unused)));
        }
        if ($this->warnings !== []) {
            $this->out->line(sprintf(' %d warning%s found (use docker --debug to expand):', \count($this->warnings), \count($this->warnings) > 1 ? 's' : ''));
            foreach ($this->warnings as $warning) {
                $this->out->line(' - '.$warning);
            }
        }
        $this->store->save();

        return new BuildResult(true, $this->out->text(), $image, $this->steps, $this->warnings, null, array_values(array_unique($this->notes)), $contextSize);
    }

    /**
     * @param array<string, \Forelse\DockerSim\Catalog\BaseImage> $resolved
     * @param array<string,string> $globalArgs
     * @param array<string,string> $buildArgs
     * @param array<string,true>   $usedArgs
     */
    private function buildStage(Stage $stage, Dockerfile $parsed, array $resolved, array $globalArgs, array $buildArgs, array &$usedArgs, BuildContext $context, bool $noCache, bool $multiStage): ?BuildResult
    {
        $counted = array_values(array_filter($stage->instructions, static fn (Instruction $i) => \in_array($i->name, ['FROM', 'RUN', 'COPY', 'ADD', 'WORKDIR'], true)));
        $total = \count($counted);
        $label = $multiStage ? ($stage->name ?? 'stage-'.$stage->index).' ' : '';
        $number = 0;

        // FROM : une étape précédente ou une image du catalogue.
        $fromRef = Variables::expand($stage->from->words()[0], $globalArgs);
        $previous = $parsed->stage($fromRef);
        ++$number;
        if ($previous !== null && $previous->index < $stage->index) {
            $state = $this->cloneState($this->stages[$previous->label()]);
            $this->recordStep(sprintf('[%s%d/%d] FROM %s', $label, $number, $total, $previous->label()), $stage->from, true, 0.0, '');
        } else {
            $base = $resolved[$fromRef];
            $image = $this->store->findImage($base->reference()) ?? ImageFactory::fromBase($base);
            $state = [
                'fs' => new MemoryFs($image->filesystem(), $image->metadata(), $image->directories()),
                'facts' => Facts::fromImage($image),
                'config' => clone $image->config,
                'layers' => $image->layers,
                'key' => hash('sha256', $base->digest()),
                'base' => $base->reference(),
                'kind' => $base->kind,
                'os' => $base->os,
                'phpVersion' => $base->phpVersion,
                'docroot' => $base->docroot,
            ];
            [$repository, $tag] = Catalog::split($fromRef);
            $step = $this->out->step(sprintf('[%s%d/%d] FROM %s@%s', $label, $number, $total, Catalog::canonical($repository, $tag), $base->digest()));
            $pulled = $this->store->findImage($base->reference()) !== null || isset($this->store->buildCache['base:'.$base->reference()]);
            if (!$pulled) {
                $this->out->stepLine($step, sprintf('resolve %s@%s done', Catalog::canonical($repository, $tag), $base->digest()));
                $this->out->stepLine($step, sprintf('sha256:%s %s / %s %.1fs done', substr(hash('sha256', $base->reference().'blob'), 0, 64), BuildOutput::megabytes((int) ($base->sizeBytes * 0.35)), BuildOutput::megabytes((int) ($base->sizeBytes * 0.35)), $base->sizeBytes / 90_000_000));
                $this->out->stepLine($step, sprintf('extracting sha256:%s %.1fs done', substr(hash('sha256', $base->reference().'blob'), 0, 64), $base->sizeBytes / 200_000_000));
                $this->store->buildCache['base:'.$base->reference()] = ['pulled' => true];
            }
            $seconds = $pulled ? 0.0 : $base->sizeBytes / 60_000_000;
            $this->out->stepDone($step, $seconds);
            $this->steps[] = ['name' => sprintf('[%s%d/%d] FROM %s', $label, $number, $total, $fromRef), 'instruction' => 'FROM', 'cached' => $pulled, 'seconds' => $seconds, 'line' => $stage->from->line, 'output' => ''];
        }

        // Variables de l'étape : ARG redéclarés et ENV de l'image.
        $args = [];
        $user = $state['config']->user ?? 'root';

        foreach ($stage->instructions as $instruction) {
            if ($instruction === $stage->from) {
                continue;
            }
            $variables = $state['config']->env + $args;
            $undefined = [];
            switch ($instruction->name) {
                case 'ARG':
                    foreach (preg_split('/\s+/', trim($instruction->arguments)) ?: [] as $declaration) {
                        [$name, $default] = array_pad(explode('=', $declaration, 2), 2, null);
                        if (\array_key_exists($name, $buildArgs)) {
                            $args[$name] = $buildArgs[$name];
                            $usedArgs[$name] = true;
                        } elseif (\array_key_exists($name, $globalArgs)) {
                            $args[$name] = $globalArgs[$name];
                        } elseif ($default !== null) {
                            $args[$name] = Variables::unquote(Variables::expand($default, $variables));
                        } else {
                            $args[$name] = '';
                        }
                    }
                    $state['key'] = hash('sha256', $state['key'].'|ARG|'.json_encode($args));
                    break;
                case 'ENV':
                    [$pairs] = Variables::pairs($instruction->arguments);
                    foreach ($pairs as $key => $value) {
                        $state['config']->env[$key] = Variables::expand($value, $state['config']->env + $args, $undefined);
                    }
                    $this->undefinedWarnings($undefined, $instruction);
                    $state['layers'][] = $this->metadataLayer($instruction, $state);
                    break;
                case 'LABEL':
                    [$pairs] = Variables::pairs($instruction->arguments);
                    foreach ($pairs as $key => $value) {
                        $state['config']->labels[Variables::unquote($key)] = Variables::expand($value, $variables);
                    }
                    $state['layers'][] = $this->metadataLayer($instruction, $state);
                    break;
                case 'EXPOSE':
                    foreach (preg_split('/\s+/', Variables::expand($instruction->arguments, $variables)) ?: [] as $port) {
                        $state['config']->exposed[] = str_contains($port, '/') ? $port : $port.'/tcp';
                    }
                    $state['config']->exposed = array_values(array_unique($state['config']->exposed));
                    $state['layers'][] = $this->metadataLayer($instruction, $state);
                    break;
                case 'CMD':
                    $state['config']->cmd = $instruction->exec ?? [...$state['config']->shell, $instruction->arguments];
                    $state['layers'][] = $this->metadataLayer($instruction, $state);
                    break;
                case 'ENTRYPOINT':
                    $state['config']->entrypoint = $instruction->exec ?? [...$state['config']->shell, $instruction->arguments];
                    // Définir ENTRYPOINT efface le CMD hérité de l'image de base.
                    if (!$this->stageDefinesCmdBefore($stage, $instruction)) {
                        $state['config']->cmd = null;
                    }
                    $state['layers'][] = $this->metadataLayer($instruction, $state);
                    break;
                case 'USER':
                    $user = Variables::expand(trim($instruction->arguments), $variables);
                    $state['config']->user = $user;
                    $state['layers'][] = $this->metadataLayer($instruction, $state);
                    break;
                case 'VOLUME':
                    $volumes = $instruction->exec ?? (preg_split('/\s+/', trim($instruction->arguments)) ?: []);
                    foreach ($volumes as $volume) {
                        $state['config']->volumes[] = Variables::expand($volume, $variables);
                        $state['fs']->mkdir(Variables::expand($volume, $variables));
                    }
                    $state['layers'][] = $this->metadataLayer($instruction, $state);
                    break;
                case 'STOPSIGNAL':
                    $state['config']->stopSignal = trim($instruction->arguments);
                    break;
                case 'SHELL':
                    $state['config']->shell = $instruction->exec ?? ['/bin/sh', '-c'];
                    break;
                case 'HEALTHCHECK':
                    $state['config']->healthcheck = $this->healthcheck($instruction);
                    $state['layers'][] = $this->metadataLayer($instruction, $state);
                    break;
                case 'ONBUILD':
                case 'MAINTAINER':
                    break;
                case 'WORKDIR':
                    ++$number;
                    $path = Path::normalize(Variables::expand(trim($instruction->arguments), $variables, $undefined), $state['config']->workdir);
                    $this->undefinedWarnings($undefined, $instruction);
                    $state['config']->workdir = $path;
                    $name = sprintf('[%s%d/%d] WORKDIR %s', $label, $number, $total, $path);
                    $key = hash('sha256', $state['key'].'|WORKDIR|'.$path);
                    $this->runCachedStep($name, $instruction, $state, $key, $noCache, function () use (&$state, $path): array {
                        $state['fs']->mkdir($path);

                        return [0, '', 0.0];
                    });
                    break;
                case 'RUN':
                    ++$number;
                    $error = $this->runStep($instruction, $state, $args, $user, $label, $number, $total, $noCache, $context);
                    if ($error !== null) {
                        return $error;
                    }
                    break;
                case 'COPY':
                case 'ADD':
                    ++$number;
                    $error = $this->copyStep($instruction, $state, $variables, $label, $number, $total, $noCache, $context, $parsed, $resolved, $globalArgs, $stage);
                    if ($error !== null) {
                        return $error;
                    }
                    break;
            }
        }
        $state['config']->user = $user === 'root' && ($state['config']->user === null) ? null : $state['config']->user;
        $this->stages[$stage->label()] = $state;

        return null;
    }

    private function stageDefinesCmdBefore(Stage $stage, Instruction $entrypoint): bool
    {
        foreach ($stage->instructions as $instruction) {
            if ($instruction === $entrypoint) {
                return false;
            }
            if ($instruction->name === 'CMD') {
                return true;
            }
        }

        return false;
    }

    /** @return array{test: list<string>, interval?: string, timeout?: string, startPeriod?: string, retries?: int}|null */
    private function healthcheck(Instruction $instruction): ?array
    {
        $arguments = trim($instruction->arguments);
        if (strtoupper($arguments) === 'NONE') {
            return ['test' => ['NONE']];
        }
        if (!preg_match('/^CMD\s+(.*)$/is', $arguments, $m)) {
            return null;
        }
        $command = trim($m[1]);
        $exec = str_starts_with($command, '[') ? json_decode($command, true) : null;
        $health = ['test' => \is_array($exec) ? ['CMD', ...$exec] : ['CMD-SHELL', $command]];
        foreach (['interval' => 'interval', 'timeout' => 'timeout', 'start-period' => 'startPeriod', 'retries' => 'retries'] as $flag => $key) {
            if (isset($instruction->flags[$flag])) {
                $health[$key] = $key === 'retries' ? (int) $instruction->flags[$flag] : $instruction->flags[$flag];
            }
        }

        return $health;
    }

    /** @param array<string,mixed> $state */
    private function metadataLayer(Instruction $instruction, array &$state): Layer
    {
        $state['key'] = hash('sha256', $state['key'].'|'.$instruction->name.'|'.$instruction->arguments.'|'.json_encode($instruction->exec));

        return new Layer('sha256:'.$state['key'], $instruction->summary(200), 0, $state['key'], [], [], time(), true);
    }

    /**
     * @param array<string,mixed>  $state
     * @param array<string,string> $args
     */
    private function runStep(Instruction $instruction, array &$state, array $args, string $user, string $label, int $number, int $total, bool $noCache, BuildContext $context): ?BuildResult
    {
        $display = $instruction->exec !== null ? 'RUN '.json_encode($instruction->exec, \JSON_UNESCAPED_SLASHES) : 'RUN '.preg_replace('/\s+/', ' ', $instruction->arguments);
        $name = sprintf('[%s%d/%d] %s', $label, $number, $total, $display);
        $argsInKey = array_filter($args, static fn ($v, $k) => str_contains($instruction->arguments.($instruction->heredoc ?? ''), $k), ARRAY_FILTER_USE_BOTH);
        $key = hash('sha256', $state['key'].'|RUN|'.$instruction->arguments.'|'.($instruction->heredoc ?? '').'|'.json_encode($instruction->exec).'|'.json_encode($argsInKey).'|'.$user.'|'.json_encode($state['config']->shell));
        $processLabel = $instruction->exec !== null ? json_encode($instruction->exec, \JSON_UNESCAPED_SLASHES) : implode(' ', $state['config']->shell).' '.$instruction->arguments;
        $failure = null;
        $this->runCachedStep($name, $instruction, $state, $key, $noCache, function () use (&$state, $instruction, $args, $user, $context, &$failure, $processLabel): array {
            $machine = new Machine(
                fs: $state['fs'],
                facts: $state['facts'],
                env: $state['config']->env + $args,
                cwd: $state['config']->workdir,
                user: $user,
                mode: Machine::BUILD,
                hostProject: $context->directory,
            );
            if (!$machine->isRoot()) {
                $name = explode(':', $user)[0];
                if (!ctype_digit($name) && !isset($state['facts']->users[$name])) {
                    $failure = sprintf('unable to find user %s: invalid argument', $name);

                    return [1, '', 0.0];
                }
            }
            $shell = $state['config']->shell;
            if ($instruction->exec !== null) {
                $code = $this->shell->runArgv($instruction->exec, $machine);
            } elseif (\in_array(basename($shell[0]), ['bash'], true) && !$state['facts']->hasBinary('bash')) {
                $failure = sprintf('process "%s" did not complete successfully: exit code: 127', $processLabel);
                $machine->output = sprintf("runc run failed: unable to start container process: error during container init: exec: \"%s\": stat %s: no such file or directory\n", $shell[0], $shell[0]);

                return [127, $machine->output, 0.0];
            } else {
                $script = $instruction->arguments;
                $stdin = '';
                if ($instruction->heredoc !== null) {
                    if (preg_match('/^<<-?\s*[\'"]?\w+[\'"]?\s*$/', trim($script))) {
                        $script = $instruction->heredoc;
                    } else {
                        $script = preg_replace('/<<-?\s*[\'"]?\w+[\'"]?/', '', $script) ?? $script;
                        $stdin = $instruction->heredoc;
                    }
                }
                $code = $this->shell->run($script, $machine, $stdin);
            }
            array_push($this->notes, ...$machine->notes);
            if ($code !== 0 && $failure === null) {
                $failure = sprintf('process "%s" did not complete successfully: exit code: %d', $processLabel, $code);
            }

            return [$code, $machine->output, $machine->elapsed];
        });
        if ($failure !== null) {
            return $this->fail('ERROR: failed to build: failed to solve: '.$failure, excerptLine: $instruction->line, failedStep: $name, endLine: $instruction->endLine);
        }

        return null;
    }

    /**
     * @param array<string,mixed>  $state
     * @param array<string,string> $variables
     * @param array<string, \Forelse\DockerSim\Catalog\BaseImage> $resolved
     * @param array<string,string> $globalArgs
     */
    private function copyStep(Instruction $instruction, array &$state, array $variables, string $label, int $number, int $total, bool $noCache, BuildContext $context, Dockerfile $parsed, array $resolved, array $globalArgs, Stage $stage): ?BuildResult
    {
        $undefined = [];
        // Docker retire les guillemets autour des chemins : COPY x "$PHP_INI_DIR/conf.d/y.ini".
        $words = array_map(fn ($w) => Variables::unquote(Variables::expand($w, $variables, $undefined)), $instruction->words());
        $this->undefinedWarnings($undefined, $instruction);
        $flags = $instruction->flags;
        $name = sprintf('[%s%d/%d] %s', $label, $number, $total, $instruction->name.($flags !== [] ? ' '.implode(' ', array_map(static fn ($k, $v) => '--'.$k.($v !== '' ? '='.$v : ''), array_keys($flags), $flags)) : '').' '.implode(' ', $words));

        // COPY <<EOF /chemin
        if ($instruction->heredoc !== null && \count($words) >= 1 && str_starts_with($words[0], '<<')) {
            $destination = Path::normalize(end($words), $state['config']->workdir);
            $key = hash('sha256', $state['key'].'|COPYHEREDOC|'.$destination.'|'.$instruction->heredoc.'|'.json_encode($flags));
            $this->runCachedStep($name, $instruction, $state, $key, $noCache, function () use (&$state, $destination, $instruction, $flags): array {
                $state['fs']->write($destination, $instruction->heredoc, isset($flags['chmod']) ? octdec($flags['chmod']) : null, isset($flags['chown']) ? explode(':', $flags['chown'])[0] : null);

                return [0, '', 0.0];
            });

            return null;
        }
        if (\count($words) < 2) {
            return $this->fail(sprintf('ERROR: failed to build: failed to solve: dockerfile parse error on line %d: %s requires at least two arguments, but only one was provided. Destination could not be determined', $instruction->line, $instruction->name), excerptLine: $instruction->line, endLine: $instruction->endLine);
        }
        $destinationRaw = array_pop($words);
        $sources = $words;
        $destination = Path::normalize($destinationRaw, $state['config']->workdir);
        $toDirectory = str_ends_with($destinationRaw, '/') || \count($sources) > 1 || $state['fs']->isDir($destination) || $destinationRaw === '.';

        $owner = null;
        if (isset($flags['chown'])) {
            $owner = explode(':', Variables::expand($flags['chown'], $variables))[0];
            if (!ctype_digit($owner) && !isset($state['facts']->users[$owner])) {
                $step = $this->out->step($name);
                $message = sprintf('unable to convert uid/gid chown string to host mapping: can\'t find uid for user %s: no such user: %s', $owner, $owner);
                $this->out->stepError($step, $message);

                return $this->fail('ERROR: failed to build: failed to solve: '.$message, excerptLine: $instruction->line, failedStep: $name, endLine: $instruction->endLine);
            }
        }
        $mode = isset($flags['chmod']) ? octdec($flags['chmod']) : null;

        // Sources : une étape, une image, ou le contexte.
        $plan = [];
        $fromFs = null;
        if (isset($flags['from'])) {
            $fromLabel = Variables::expand($flags['from'], $globalArgs);
            $fromStage = $parsed->stage($fromLabel);
            if ($fromStage !== null && isset($this->stages[$fromStage->label()])) {
                $fromFs = $this->stages[$fromStage->label()]['fs'];
                $fromKey = $this->stages[$fromStage->label()]['key'];
            } elseif (isset($resolved[$fromLabel])) {
                $image = ImageFactory::fromBase($resolved[$fromLabel]);
                $fromFs = new MemoryFs($image->filesystem(), $image->metadata(), $image->directories());
                $fromFs = $this->withBinaries($fromFs, $resolved[$fromLabel]);
                $fromKey = $resolved[$fromLabel]->digest();
            } else {
                return $this->fail(sprintf('ERROR: failed to build: failed to solve: %s: failed to resolve source metadata for docker.io/library/%s: not found', $fromLabel, $fromLabel), excerptLine: $instruction->line, endLine: $instruction->endLine);
            }
            foreach ($sources as $source) {
                $path = Path::normalize($source, '/');
                if ($fromFs->isFile($path)) {
                    $plan[] = ['from', $path, $toDirectory ? rtrim($destination, '/').'/'.basename($path) : $destination];
                } elseif ($fromFs->isDir($path)) {
                    foreach ($fromFs->files($path) as $file) {
                        $plan[] = ['from', $file, rtrim($destination, '/').substr($file, \strlen(rtrim($path, '/')))];
                    }
                    if ($fromFs->files($path) === []) {
                        $plan[] = ['mkdir', '', $destination];
                    }
                } else {
                    $step = $this->out->step($name);
                    $message = sprintf('failed to compute cache key: failed to calculate checksum of ref %s::%s: "%s": not found', substr(hash('sha256', $fromLabel), 0, 25), substr(hash('sha256', $source), 0, 25), $path);
                    $this->out->stepError($step, $message);

                    return $this->fail('ERROR: failed to build: failed to solve: '.$message, excerptLine: $instruction->line, failedStep: $name, endLine: $instruction->endLine);
                }
            }
            $key = hash('sha256', $state['key'].'|COPYFROM|'.$fromKey.'|'.json_encode($sources).'|'.$destination.'|'.json_encode($flags));
        } else {
            $matched = [];
            foreach ($sources as $source) {
                if ($instruction->name === 'ADD' && preg_match('#^https?://#', $source)) {
                    $plan[] = ['url', $source, $toDirectory ? rtrim($destination, '/').'/'.basename(parse_url($source, PHP_URL_PATH) ?: 'index.html') : $destination];
                    continue;
                }
                $files = $context->match($source);
                if ($files === null) {
                    $step = $this->out->step($name);
                    $message = sprintf('failed to compute cache key: failed to calculate checksum of ref %s::%s: "/%s": not found', substr(hash('sha256', $context->directory), 0, 25), substr(hash('sha256', $source), 0, 25), trim(preg_replace('#^\./#', '', $source) ?? $source, '/'));
                    $this->out->stepError($step, $message);
                    if (str_starts_with(ltrim($source, './'), '..') || str_starts_with($source, '../')) {
                        $this->notes[] = 'COPY ne peut rien prendre en dehors du contexte de build (le dossier passé à docker build).';
                    } elseif ($context->ignore->ignores(trim($source, './'))) {
                        $this->notes[] = sprintf('« %s » est exclu par le .dockerignore : il n\'est pas envoyé au démon.', $source);
                    }

                    return $this->fail('ERROR: failed to build: failed to solve: '.$message, excerptLine: $instruction->line, failedStep: $name, endLine: $instruction->endLine);
                }
                $isDirectorySource = !$context->has(trim(preg_replace('#^\./#', '', $source) ?? $source, '/')) && strpbrk($source, '*?[') === false;
                foreach ($files as $file => $relative) {
                    $target = $toDirectory || $isDirectorySource ? rtrim($destination, '/').'/'.$relative : $destination;
                    $plan[] = ['context', $file, $target];
                    $matched[] = $file;
                }
            }
            $key = hash('sha256', $state['key'].'|COPY|'.$context->checksum($matched).'|'.$destination.'|'.json_encode($flags).'|'.json_encode($sources));
        }

        $this->runCachedStep($name, $instruction, $state, $key, $noCache, function () use (&$state, $plan, $context, $fromFs, $owner, $mode): array {
            $bytes = 0;
            foreach ($plan as [$kind, $source, $target]) {
                if ($kind === 'mkdir') {
                    $state['fs']->mkdir($target, $owner);
                    continue;
                }
                if ($kind === 'url') {
                    $state['fs']->write($target, "# téléchargé depuis {$source} (simulé)\n", $mode ?? 0600, $owner);
                    continue;
                }
                if ($kind === 'from') {
                    $blob = (string) $fromFs->blob($source);
                    $state['fs']->putBlob($target, $blob, $mode ?? $fromFs->mode($source), $owner);
                    $bytes += Blob::size($blob);
                    // Un binaire copié depuis une autre image (COPY --from=composer:2 /usr/bin/composer) devient disponible.
                    if (preg_match('#^/(usr/(local/)?)?s?bin/#', $target) && \in_array(basename($source), ['composer', 'install-php-extensions', 'frankenphp', 'caddy', 'node', 'npm'], true)) {
                        $state['facts']->binaries[] = basename($target);
                        $state['facts']->binaries = array_values(array_unique($state['facts']->binaries));
                    }
                    continue;
                }
                $absolute = $context->absolute($source);
                $hostMode = @fileperms($absolute);
                $executable = $hostMode !== false && ($hostMode & 0111) !== 0;
                $state['fs']->writeFromHost($target, $absolute, $mode ?? ($executable ? 0755 : 0644), $owner);
                $bytes += (int) @filesize($absolute);
                if ($owner !== null) {
                    // --chown s'applique aussi aux dossiers créés pour l'occasion.
                    $dir = \dirname($target);
                    while ($dir !== '/' && $state['fs']->owner($dir) === 'root' && !\in_array($dir, ['/var', '/var/www', '/usr', '/opt', '/home', '/srv', '/app'], true)) {
                        $state['fs']->chown($dir, $owner);
                        $dir = \dirname($dir);
                    }
                }
            }

            return [0, '', max(0.05, $bytes / 50_000_000)];
        });

        return null;
    }

    private function withBinaries(MemoryFs $fs, \Forelse\DockerSim\Catalog\BaseImage $base): MemoryFs
    {
        // Les binaires d'une image du catalogue n'ont pas de fichier : on en crée pour COPY --from.
        foreach ($base->binaries as $binary) {
            foreach (['/usr/bin/', '/usr/local/bin/'] as $dir) {
                if (!$fs->isFile($dir.$binary)) {
                    $fs->putBlob($dir.$binary, Blob::virtual(\in_array($binary, ['composer'], true) ? 3_100_000 : 200_000), 0755);
                }
            }
        }

        return $fs;
    }

    /**
     * Joue une étape, ou la reprend du cache si sa clé est connue.
     *
     * @param array<string,mixed>              $state
     * @param callable(): array{0:int,1:string,2:float} $execute
     */
    private function runCachedStep(string $name, Instruction $instruction, array &$state, string $key, bool $noCache, callable $execute): void
    {
        $step = $this->out->step($name);
        if (!$noCache && isset($this->store->buildCache[$key]['layer'])) {
            $cached = $this->store->buildCache[$key];
            $layer = Layer::fromArray($cached['layer']);
            foreach ($layer->deleted as $path) {
                $state['fs']->delete($path);
            }
            foreach ($layer->dirs as $dir) {
                $state['fs']->mkdir($dir);
            }
            foreach ($layer->files as $path => $blob) {
                $state['fs']->putBlob($path, $blob, $layer->meta[$path][0] ?? null, $layer->meta[$path][1] ?? null);
            }
            foreach ($layer->meta as $path => [$mode, $owner]) {
                if ($state['fs']->exists($path)) {
                    $state['fs']->chmod($path, $mode);
                    $state['fs']->chown($path, $owner);
                }
            }
            $factsData = $cached['facts'];
            $state['facts']->packages = [];
            $restored = Facts::fromImage(new Image('x', [], [], new ImageConfig(), '', $state['facts']->kind, $state['facts']->os, $factsData['packages'], $factsData['phpExtensions'], $factsData['binaries'], $factsData['apacheModules'], $state['facts']->phpVersion, null, 0, false, null, $factsData['users']));
            $state['facts']->packages = $restored->packages;
            $state['facts']->phpExtensions = $restored->phpExtensions;
            $state['facts']->peclBuilt = $restored->peclBuilt;
            $state['facts']->binaries = $restored->binaries;
            $state['facts']->apacheModules = $restored->apacheModules;
            $state['facts']->users = $restored->users;
            $state['layers'][] = $layer;
            $state['key'] = $key;
            $this->out->stepCached($step);
            $this->steps[] = ['name' => $name, 'instruction' => $instruction->name, 'cached' => true, 'seconds' => (float) ($cached['seconds'] ?? 0), 'line' => $instruction->line, 'output' => (string) ($cached['output'] ?? '')];
            $this->out->stepDone($step, null);

            return;
        }
        $state['fs']->beginLayer();
        [$code, $output, $seconds] = $execute();
        $this->out->stepOutput($step, $output, $seconds);
        $this->steps[] = ['name' => $name, 'instruction' => $instruction->name, 'cached' => false, 'seconds' => $seconds, 'line' => $instruction->line, 'output' => $output];
        if ($code !== 0) {
            $this->out->stepErrorPending($step, $name, $output);

            return;
        }
        $changes = $state['fs']->layerChanges();
        $layer = new Layer('sha256:'.$key, $this->historyLine($instruction), $changes['size'], $key, $changes['files'], $changes['deleted'], time(), false, $changes['meta'], $changes['dirs']);
        $state['layers'][] = $layer;
        $state['key'] = $key;
        $this->store->buildCache[$key] = ['layer' => $layer->toArray(), 'facts' => $state['facts']->export(), 'seconds' => $seconds, 'output' => $output];
        $this->out->stepDone($step, $seconds);
    }

    private function historyLine(Instruction $instruction): string
    {
        return match ($instruction->name) {
            'RUN' => 'RUN /bin/sh -c '.preg_replace('/\s+/', ' ', $instruction->arguments).' # buildkit',
            default => preg_replace('/\s+/', ' ', trim($instruction->original)).' # buildkit',
        };
    }

    /** @param array<string,mixed> $state */
    private function cloneState(array $state): array
    {
        $fs = new MemoryFs($state['fs']->allBlobs(), [], []);
        foreach ($state['fs']->files() as $file) {
            $fs->chmod($file, $state['fs']->mode($file));
            $fs->chown($file, $state['fs']->owner($file));
        }
        $state['fs'] = $fs;
        $state['facts'] = clone $state['facts'];
        $state['config'] = clone $state['config'];

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function exportImage(array $state, array $tags, Stage $stage): Image
    {
        $facts = $state['facts']->export();
        $config = $state['config'];
        $id = 'sha256:'.hash('sha256', $state['key'].json_encode($config->toArray()));
        $existing = $this->store->images[$id] ?? null;
        $normalized = array_map(Store::normalizeTag(...), $tags);
        foreach ($this->store->images as $other) {
            if ($other->id !== $id) {
                $other->tags = array_values(array_diff($other->tags, $normalized));
            }
        }
        $image = new Image(
            id: $id,
            tags: array_values(array_unique([...($existing?->tags ?? []), ...$normalized])),
            layers: $state['layers'],
            config: $config,
            base: $state['base'],
            kind: $state['kind'],
            os: $state['os'],
            packages: $facts['packages'],
            phpExtensions: $facts['phpExtensions'],
            binaries: $facts['binaries'],
            apacheModules: $facts['apacheModules'],
            phpVersion: $state['phpVersion'],
            docroot: $state['docroot'],
            createdAt: $existing?->createdAt ?? time(),
            pulled: false,
            stage: $stage->name,
            users: $facts['users'],
        );
        $this->store->images[$id] = $image;
        // Les images sans tag et sans conteneur disparaissent de la liste « dangling » au prochain prune.
        return $image;
    }

    // --- Analyse du Dockerfile --------------------------------------------------------------

    /**
     * @param array<string,string> $buildArgs
     *
     * @return array<string,string>
     */
    private function globalArgs(Dockerfile $parsed, array $buildArgs): array
    {
        $args = [];
        foreach ($parsed->globalArgs as $instruction) {
            foreach (preg_split('/\s+/', trim($instruction->arguments)) ?: [] as $declaration) {
                [$name, $default] = array_pad(explode('=', $declaration, 2), 2, null);
                $args[$name] = $buildArgs[$name] ?? Variables::unquote((string) $default);
            }
        }

        return $args;
    }

    /**
     * @param array<string,string> $globalArgs
     *
     * @return list<Stage>
     */
    private function neededStages(Dockerfile $parsed, Stage $target, array $globalArgs): array
    {
        $needed = [];
        $visit = function (Stage $stage) use (&$visit, &$needed, $parsed, $globalArgs): void {
            if (isset($needed[$stage->index])) {
                return;
            }
            $needed[$stage->index] = $stage;
            $from = $parsed->stage(Variables::expand($stage->from->words()[0], $globalArgs));
            if ($from !== null && $from->index < $stage->index) {
                $visit($from);
            }
            foreach ($stage->instructions as $instruction) {
                if (isset($instruction->flags['from'])) {
                    $source = $parsed->stage(Variables::expand($instruction->flags['from'], $globalArgs));
                    if ($source !== null && $source->index < $stage->index) {
                        $visit($source);
                    }
                }
            }
        };
        $visit($target);
        ksort($needed);

        return array_values($needed);
    }

    /**
     * @param list<Stage>          $stages
     * @param array<string,string> $globalArgs
     *
     * @return array<string,int>
     */
    private function copyFromImages(array $stages, Dockerfile $parsed, array $globalArgs): array
    {
        $images = [];
        foreach ($stages as $stage) {
            foreach ($stage->instructions as $instruction) {
                if (isset($instruction->flags['from'])) {
                    $reference = Variables::expand($instruction->flags['from'], $globalArgs);
                    if ($parsed->stage($reference) === null) {
                        $images[$reference] = $instruction->line;
                    }
                }
            }
        }

        return $images;
    }

    private function lint(Dockerfile $parsed): void
    {
        $uppercase = 0;
        $lowercase = 0;
        $all = [...$parsed->globalArgs];
        foreach ($parsed->stages as $stage) {
            array_push($all, ...$stage->instructions);
        }
        foreach ($all as $instruction) {
            $keyword = strtok(ltrim($instruction->original), " \t\n") ?: '';
            ctype_upper(str_replace(['-', '_'], '', $keyword)) ? ++$uppercase : ++$lowercase;
        }
        foreach ($all as $instruction) {
            $keyword = strtok(ltrim($instruction->original), " \t\n") ?: '';
            if ($uppercase >= $lowercase && !ctype_upper($keyword)) {
                $this->warnings[] = sprintf("ConsistentInstructionCasing: Command '%s' should match the case of the command majority (uppercase) (line %d)", $keyword, $instruction->line);
            }
        }
        foreach ($parsed->stages as $stage) {
            $fromWords = preg_split('/\s+/', trim($stage->from->original)) ?: [];
            if (\count($fromWords) === 4 && (ctype_upper($fromWords[0]) !== ctype_upper($fromWords[2]))) {
                $this->warnings[] = sprintf("FromAsCasing: '%s' and '%s' keywords' casing do not match (line %d)", $fromWords[2], $fromWords[0], $stage->from->line);
            }
            if ($stage->name !== null && strtolower($stage->name) !== $stage->name) {
                $this->warnings[] = sprintf("StageNameCasing: Stage name '%s' should be lowercase (line %d)", $stage->name, $stage->from->line);
            }
            $seen = [];
            foreach ($stage->instructions as $instruction) {
                if (\in_array($instruction->name, ['CMD', 'ENTRYPOINT', 'HEALTHCHECK'], true)) {
                    if (isset($seen[$instruction->name])) {
                        $this->warnings[] = sprintf('MultipleInstructionsDisallowed: Multiple %s instructions should not be used in the same stage because only the last one will be used (line %d)', $instruction->name, $seen[$instruction->name]);
                    }
                    $seen[$instruction->name] = $instruction->line;
                    if ($instruction->name !== 'HEALTHCHECK' && $instruction->exec === null && trim($instruction->arguments) !== '') {
                        $this->warnings[] = sprintf('JSONArgsRecommended: JSON arguments recommended for %s to prevent unintended behavior related to OS signals (line %d)', $instruction->name, $instruction->line);
                    }
                }
                if (\in_array($instruction->name, ['ENV', 'LABEL'], true)) {
                    [$pairs, $legacy] = Variables::pairs($instruction->arguments);
                    if ($legacy) {
                        $this->warnings[] = sprintf('LegacyKeyValueFormat: "%s key=value" should be used instead of legacy "%s key value" format (line %d)', $instruction->name, $instruction->name, $instruction->line);
                    }
                }
                if (\in_array($instruction->name, ['ENV', 'ARG'], true)) {
                    $names = $instruction->name === 'ENV' ? array_keys(Variables::pairs($instruction->arguments)[0]) : array_map(static fn ($d) => explode('=', $d, 2)[0], preg_split('/\s+/', trim($instruction->arguments)) ?: []);
                    foreach ($names as $name) {
                        if (preg_match('/(PASSWORD|PASSWD|SECRET|TOKEN|API_?KEY|PRIVATE_?KEY|CREDENTIALS?)/i', $name) && !preg_match('/(_FILE|_PATH|_URL)$/i', $name)) {
                            $this->warnings[] = sprintf('SecretsUsedInArgOrEnv: Do not use ARG or ENV instructions for sensitive data (%s "%s") (line %d)', $instruction->name, $name, $instruction->line);
                        }
                    }
                }
                if ($instruction->name === 'WORKDIR' && !str_starts_with(trim($instruction->arguments), '/') && !str_starts_with(trim($instruction->arguments), '$')) {
                    $this->warnings[] = sprintf('WorkdirRelativePath: Relative workdir "%s" can have unexpected results if the base image changes (line %d)', trim($instruction->arguments), $instruction->line);
                }
                if ($instruction->name === 'MAINTAINER') {
                    $this->warnings[] = sprintf('MaintainerDeprecated: Maintainer instruction is deprecated in favor of using label (line %d)', $instruction->line);
                }
            }
        }
    }

    /** @param list<string> $undefined */
    private function undefinedWarnings(array $undefined, Instruction $instruction): void
    {
        foreach (array_unique($undefined) as $name) {
            $warning = sprintf("UndefinedVar: Usage of undefined variable '$%s' (line %d)", $name, $instruction->line);
            if (!\in_array($warning, $this->warnings, true)) {
                $this->warnings[] = $warning;
            }
        }
    }

    private function suggestInstruction(string $word): ?string
    {
        $best = null;
        $distance = 3;
        foreach (['FROM', 'RUN', 'CMD', 'LABEL', 'EXPOSE', 'ENV', 'ADD', 'COPY', 'ENTRYPOINT', 'VOLUME', 'USER', 'WORKDIR', 'ARG', 'HEALTHCHECK', 'SHELL'] as $candidate) {
            $d = levenshtein(strtoupper($word), $candidate);
            if ($d < $distance) {
                $distance = $d;
                $best = $candidate;
            }
        }

        return $best;
    }

    private function recordStep(string $name, Instruction $instruction, bool $cached, float $seconds, string $output): void
    {
        $step = $this->out->step($name);
        $this->out->stepDone($step, 0.0);
        $this->steps[] = ['name' => $name, 'instruction' => $instruction->name, 'cached' => $cached, 'seconds' => $seconds, 'line' => $instruction->line, 'output' => $output];
    }

    private function fail(string $message, ?int $excerptLine = null, bool $withStep = true, ?string $failedStep = null, ?int $endLine = null): BuildResult
    {
        if ($excerptLine !== null && $this->dockerfileText !== '') {
            $this->out->excerpt($this->dockerfileText, $excerptLine, preg_replace('/^ERROR: failed to build: failed to solve: /', '', $message) ?? $message, $endLine);
        }
        $this->out->line($message);
        $this->store->save();

        return new BuildResult(false, $this->out->text(), null, $this->steps, $this->warnings, $message, array_values(array_unique($this->notes)));
    }
}
