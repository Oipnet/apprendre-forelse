<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Engine;

use Forelse\DockerSim\Http\HttpRequest;
use Forelse\DockerSim\Shell\Network;
use Forelse\DockerSim\State\Container;

/**
 * Le réseau vu depuis un conteneur. Sur un réseau créé par l'utilisateur (ou par compose), le DNS
 * de Docker résout le nom des conteneurs, de leurs services et de leurs alias ; sur le réseau
 * « bridge » par défaut, il ne résout rien. Un serveur qui écoute sur 127.0.0.1 n'est joignable
 * que depuis son propre conteneur.
 */
final class ContainerNetwork implements Network
{
    public function __construct(private readonly Docker $docker, private readonly Container $container)
    {
    }

    public function resolve(string $host): ?string
    {
        return $this->find($host)[1];
    }

    /** @return array{0: ?Container, 1: ?string} */
    public function find(string $host): array
    {
        $host = strtolower($host);
        if (\in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return [$this->container, '127.0.0.1'];
        }
        if ($host === strtolower($this->container->hostname) || $host === strtolower($this->container->name) || str_starts_with($this->container->id, $host) && \strlen($host) >= 12) {
            return [$this->container, reset($this->container->ips) ?: '172.17.0.2'];
        }
        if ($host === 'host.docker.internal') {
            return [null, '192.168.65.254'];
        }
        foreach ($this->container->networks as $networkName => $aliases) {
            $network = $this->docker->store->networks[$networkName] ?? null;
            if ($network === null || $network->builtin) {
                continue;
            }
            foreach ($this->docker->store->containers as $other) {
                if ($other->id === $this->container->id || !$other->isRunning() || !isset($other->networks[$networkName])) {
                    continue;
                }
                $names = array_map('strtolower', [$other->name, substr($other->id, 0, 12), ...$other->networks[$networkName]]);
                if (\in_array($host, $names, true)) {
                    return [$other, $other->ips[$networkName] ?? null];
                }
            }
        }

        return [null, null];
    }

    /** L'adresse qu'un serveur voit pour ce client : 127.0.0.1 depuis le conteneur lui-même, sinon son IP sur un réseau commun. */
    private function clientIp(?Container $target): string
    {
        if ($target === null) {
            return reset($this->container->ips) ?: '172.17.0.1';
        }
        if ($target->id === $this->container->id) {
            return '127.0.0.1';
        }
        foreach ($this->container->ips as $network => $ip) {
            if (isset($target->ips[$network])) {
                return $ip;
            }
        }

        return reset($this->container->ips) ?: '172.17.0.1';
    }

    public function connect(string $host, int $port): array
    {
        [$target, $ip] = $this->find($host);
        if ($ip === null) {
            return ['status' => 'unresolved', 'process' => null, 'container' => null];
        }
        if ($target === null || !$target->isRunning() || !\in_array($port, $target->listening, true)) {
            return ['status' => 'refused', 'process' => $target?->process, 'container' => $target];
        }
        $address = $target->listenAddresses[$port] ?? '0.0.0.0';
        if ($target->id !== $this->container->id && \in_array($address, ['127.0.0.1', 'localhost', '::1'], true)) {
            return ['status' => 'refused', 'process' => $target->process, 'container' => $target];
        }

        return ['status' => 'open', 'process' => $target->process, 'container' => $target];
    }

    public function http(string $method, string $url, array $headers = [], string $body = ''): array
    {
        $parts = parse_url($url) ?: [];
        $host = (string) ($parts['host'] ?? 'localhost');
        $port = (int) ($parts['port'] ?? 80);
        $connection = $this->connect($host, $port);
        if ($connection['status'] !== 'open') {
            return ['error' => $connection['status'], 'message' => $connection['status'] === 'unresolved' ? "nom inconnu : {$host}" : "connexion refusée : {$host}:{$port}"];
        }
        $request = HttpRequest::fromUrl($method, ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : ''), ['host' => $host.($port !== 80 ? ':'.$port : '')] + $headers, $body, $this->clientIp($connection['container']), $host, $port);
        $response = $this->docker->serve($connection['container'], $port, $request);
        if ($response->error !== null) {
            return ['error' => $response->error, 'message' => implode(' ; ', $response->trace)];
        }

        return ['status' => $response->status, 'headers' => $response->headers, 'body' => $response->body];
    }
}
