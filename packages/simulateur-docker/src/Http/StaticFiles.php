<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Http;

use Forelse\DockerSim\Fs\DiskFs;

final class StaticFiles
{
    public static function serve(DiskFs $fs, string $path, string $server): HttpResponse
    {
        $content = (string) $fs->read($path);

        return new HttpResponse(200, ['Content-Type' => Mime::type($path).(str_starts_with(Mime::type($path), 'text/') ? '; charset=utf-8' : ''), 'Content-Length' => (string) \strlen($content), 'Server' => $server], $content);
    }

    public static function accessLog(HttpRequest $request, int $status, int $bytes, string $format = 'apache'): string
    {
        $date = gmdate('d/M/Y:H:i:s +0000');
        $agent = $request->headers['user-agent'] ?? 'Mozilla/5.0';
        $referer = $request->headers['referer'] ?? '-';

        return $format === 'nginx'
            ? sprintf('%s - - [%s] "%s %s HTTP/1.1" %d %d "%s" "%s" "-"', $request->clientIp, $date, $request->method, $request->uri(), $status, $bytes, $referer, $agent)
            : sprintf('%s - - [%s] "%s %s HTTP/1.1" %d %d "%s" "%s"', $request->clientIp, $date, $request->method, $request->uri(), $status, $bytes, $referer, $agent);
    }
}
