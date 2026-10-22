<?php

/**
 * Essai php-wasm-builder : ce PHP n'exécute que des scripts, comme la commande php. Ce script joue le
 * serveur web de @php-wasm : il reçoit la demande sur stdin (JSON), remplit les superglobales, exécute
 * le script demandé, puis laisse le statut, les en-têtes et le corps de la réponse dans /tmp.
 *
 * Tout est au niveau global : public/index.php et les scripts du runner s'attendent à y être.
 */

$__demande = json_decode(file_get_contents('php://stdin'), true);
$__script = $__demande['script'];
$__http = $__demande['http'] ?? null;

foreach ($__demande['server'] as $__cle => $__valeur) {
    $_SERVER[$__cle] = $__valeur;
}
$_SERVER['SCRIPT_FILENAME'] ??= $__script;
$_SERVER['SCRIPT_NAME'] ??= $__script;
$_SERVER['PHP_SELF'] ??= $__script;

if (null !== $__http) {
    unset($_SERVER['argv'], $_SERVER['argc']);
    $__uri = $__http['uri'];
    $__query = (string) parse_url($__uri, PHP_URL_QUERY);
    $_SERVER += [
        'REQUEST_METHOD' => $__http['method'],
        'REQUEST_URI' => $__uri,
        'QUERY_STRING' => $__query,
        'SERVER_NAME' => 'localhost',
        'SERVER_PORT' => '80',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'REMOTE_ADDR' => '127.0.0.1',
        'REQUEST_TIME' => time(),
        'REQUEST_TIME_FLOAT' => microtime(true),
    ];
    $__entetes = array_change_key_case($__http['headers']);
    foreach ($__entetes as $__nom => $__valeur) {
        $__cle = strtoupper(str_replace('-', '_', $__nom));
        $_SERVER[in_array($__cle, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $__cle : 'HTTP_'.$__cle] = $__valeur;
    }
    parse_str($__query, $_GET);
    foreach (explode(';', $__entetes['cookie'] ?? '') as $__cookie) {
        if (str_contains($__cookie, '=')) {
            [$__nom, $__valeur] = explode('=', trim($__cookie), 2);
            $_COOKIE[urldecode($__nom)] = urldecode($__valeur);
        }
    }
    $__corps = base64_decode($__http['body'] ?? '');
    if ('' !== $__corps) {
        if (str_starts_with($__entetes['content-type'] ?? '', 'application/x-www-form-urlencoded')) {
            parse_str($__corps, $_POST);
        }
        $_SERVER['CONTENT_LENGTH'] ??= (string) strlen($__corps);
        EntreeDeLaRequete::installer($__corps);
    }
    $_REQUEST = $_GET + $_POST;
}

/**
 * php://input lu depuis le corps de la requête (sans serveur, ce PHP n'en a pas). Les autres flux php://
 * (stdout, memory, temp…) restent ceux de PHP : le flux natif est ouvert puis délégué.
 */
final class EntreeDeLaRequete
{
    private static string $corps;
    /** @var resource */
    private $flux;
    public mixed $context;

    public static function installer(string $corps): void
    {
        self::$corps = $corps;
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class);
    }

    public function stream_open(string $chemin, string $mode, int $options, ?string &$ouvert): bool
    {
        $entree = 0 === strcasecmp($chemin, 'php://input');
        stream_wrapper_restore('php');
        try {
            $this->flux = fopen($entree ? 'php://memory' : $chemin, $entree ? 'w+' : $mode);
        } finally {
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', self::class);
        }
        if ($entree && false !== $this->flux) {
            fwrite($this->flux, self::$corps);
            rewind($this->flux);
        }

        return false !== $this->flux;
    }

    public function stream_read(int $taille): string|false { return fread($this->flux, $taille); }
    public function stream_write(string $donnees): int|false { return fwrite($this->flux, $donnees); }
    public function stream_eof(): bool { return feof($this->flux); }
    public function stream_tell(): int|false { return ftell($this->flux); }
    public function stream_seek(int $position, int $depuis): bool { return 0 === fseek($this->flux, $position, $depuis); }
    public function stream_flush(): bool { return fflush($this->flux); }
    public function stream_truncate(int $taille): bool { return ftruncate($this->flux, $taille); }
    public function stream_stat(): array|false { return fstat($this->flux); }
    public function stream_lock(int $operation): bool { return flock($this->flux, $operation); }
    public function stream_set_option(int $option, int $a, ?int $b): bool { return false; }
    public function stream_close(): void { fclose($this->flux); }
}

// Après les fonctions d'arrêt du framework (la page d'erreur fatale de Symfony en est une) : celle-ci
// s'inscrit à l'arrêt, donc passe en dernier.
register_shutdown_function(static function (): void {
    register_shutdown_function(static function (): void {
        $corps = '';
        while (ob_get_level() > 0) {
            $corps = ob_get_clean().$corps;
        }
        file_put_contents('/tmp/reponse.corps', $corps);
        file_put_contents('/tmp/reponse.json', json_encode(['status' => http_response_code() ?: 200, 'headers' => headers_list()]));
    });
});

ob_start();
chdir(dirname($__script));
require $__script;
