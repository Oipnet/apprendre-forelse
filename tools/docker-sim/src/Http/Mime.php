<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Http;

final class Mime
{
    public static function type(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html', 'htm' => 'text/html',
            'css' => 'text/css',
            'js', 'mjs' => 'application/javascript',
            'json', 'map' => 'application/json',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'txt', 'log' => 'text/plain',
            'xml' => 'text/xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
