<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Http;

use Forelse\DockerSim\Shell\Command\NetworkCommands;

final class ErrorPages
{
    public static function apache(int $status, int $port, string $host = 'localhost'): string
    {
        $reason = NetworkCommands::reason($status);
        $message = match ($status) {
            404 => 'The requested URL was not found on this server.',
            403 => "You don't have permission to access this resource.",
            500 => 'The server encountered an internal error or misconfiguration and was unable to complete your request.</p>
<p>Please contact the server administrator at webmaster@localhost to inform them of the time this error occurred, and the actions you performed just before this error.</p>
<p>More information about this error may be available in the server error log.',
            405 => 'The requested method is not allowed for this URL.',
            default => $reason,
        };

        return "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">\n<html><head>\n<title>{$status} {$reason}</title>\n</head><body>\n<h1>{$reason}</h1>\n<p>{$message}</p>\n<hr>\n<address>Apache/2.4.65 (Debian) Server at {$host} Port {$port}</address>\n</body></html>\n";
    }

    public static function nginx(int $status): string
    {
        $reason = NetworkCommands::reason($status);

        return "<html>\n<head><title>{$status} {$reason}</title></head>\n<body>\n<center><h1>{$status} {$reason}</h1></center>\n<hr><center>nginx/1.29.1</center>\n</body>\n</html>\n";
    }

    public static function phpServer(string $path): string
    {
        return "<!doctype html><html><head><title>404 Not Found</title><style>AAA{}</style></head><body><h1>Not Found</h1><p>The requested resource <code class=\"url\">".htmlspecialchars($path)."</code> was not found on this server.</p></body></html>";
    }

    /** Ce qu'afficherait le navigateur quand rien ne répond : pas une page du serveur, une page d'erreur du navigateur. */
    public static function browser(string $error, string $host, int $port, string $detail = ''): string
    {
        [$title, $code, $explain] = match ($error) {
            'refused' => ['Ce site est inaccessible', 'ERR_CONNECTION_REFUSED', "<strong>{$host}</strong> n'autorise pas la connexion."],
            'reset' => ['Ce site est inaccessible', 'ERR_CONNECTION_RESET', 'La connexion a été réinitialisée.'],
            'empty' => ['Cette page ne fonctionne pas', 'ERR_EMPTY_RESPONSE', "<strong>{$host}</strong> n'a envoyé aucune donnée."],
            default => ['Ce site est inaccessible', 'ERR_NAME_NOT_RESOLVED', "Impossible de trouver l'adresse IP du serveur de <strong>{$host}</strong>."],
        };
        $detail = $detail !== '' ? '<details open><summary>Ce que voit le simulateur</summary><p>'.htmlspecialchars($detail).'</p></details>' : '';

        return <<<HTML
            <!DOCTYPE html>
            <html lang="fr"><head><meta charset="utf-8"><title>{$host}:{$port}</title>
            <style>
            body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#3c4043;max-width:600px;margin:14vh auto;padding:0 24px;line-height:1.5}
            .icon{width:72px;height:72px;margin-bottom:28px;opacity:.55}
            h1{font-size:1.6em;font-weight:500;color:#202124;margin:0 0 14px}
            .code{font-size:.8em;color:#5f6368;text-transform:uppercase;margin-top:18px}
            details{margin-top:28px;font-size:.9em;background:#f1f3f4;border-radius:8px;padding:10px 14px}
            summary{cursor:pointer;color:#1a73e8}
            @media (prefers-color-scheme:dark){body{background:#202124;color:#bdc1c6}h1{color:#e8eaed}details{background:#303134}}
            </style></head>
            <body>
            <svg class="icon" viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 14H4V8h16v10zM7 10h2v2H7v-2zm0 3h6v2H7v-2z"/></svg>
            <h1>{$title}</h1>
            <p>{$explain}</p>
            <div class="code">{$code}</div>
            {$detail}
            </body></html>
            HTML;
    }
}
