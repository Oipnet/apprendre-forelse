<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The base of the Gazette's hidden tests: visit a page of the project the way the browser would, without a server.
 *
 * public/index.php runs inside the tests' PHP, with the superglobals of a real request. The same page only runs once
 * per test run (it is kept in memory): before chapter 3, index.php declares its functions and classes, and loading it
 * again would redeclare them. Visiting a second address therefore needs an index.php that declares nothing, which is
 * the case once the classes live in src/.
 *
 * The site is served under a prefix (PREFIXE), as in the editor's preview or in a subfolder of a real server: a
 * hard-coded address (“/articles/…”) leads nowhere there. visiter('/articles/x') requests “/preview/articles/x”, and
 * adresse('/articles/x') gives the link the page must contain.
 *
 * Method names are the French ones of php-8-gazette (visiter = visit, adresse = address, accueil = home page), so the
 * tests of both languages share them.
 */
abstract class GazetteTestCase extends TestCase
{
    protected const string PREFIXE = '/preview';

    /** @var array<string, Page> */
    private static array $pages = [];

    /** The root of the project (where public/, src/ and composer.json live). */
    protected static function projet(string $chemin = ''): string
    {
        return dirname(__DIR__).('' === $chemin ? '' : '/'.ltrim($chemin, '/'));
    }

    /** The contents of a project file, “” if it does not exist. */
    protected static function source(string $chemin): string
    {
        $fichier = self::projet($chemin);

        return is_file($fichier) ? (string) file_get_contents($fichier) : '';
    }

    /** The link a page of the site must contain to lead to $chemin (“/articles/x” gives “/preview/articles/x”). */
    protected static function adresse(string $chemin): string
    {
        return self::PREFIXE.'/'.ltrim($chemin, '/');
    }

    /** @param array<string, string> $donnees the fields of a form sent with POST */
    protected function visiter(string $url, string $methode = 'GET', array $donnees = []): Page
    {
        $cle = $methode.' '.$url.' '.json_encode($donnees);
        if (isset(self::$pages[$cle])) {
            return self::$pages[$cle];
        }
        $index = self::projet('public/index.php');
        $this->assertFileExists($index, 'The file public/index.php cannot be found.');
        if ([] !== self::$pages && 1 === preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(function|class|enum|interface|trait)\s+\w+/mi', (string) file_get_contents($index))) {
            $this->fail('public/index.php still declares functions or classes: move them to src/, otherwise the page can only be loaded once.');
        }

        $sauvegarde = [$_SERVER, $_GET, $_POST, $_REQUEST];
        $chemin = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $requete);
        $_SERVER = [...$_SERVER, 'REQUEST_METHOD' => $methode, 'REQUEST_URI' => self::PREFIXE.$url, 'QUERY_STRING' => (string) parse_url($url, PHP_URL_QUERY), 'SCRIPT_NAME' => self::PREFIXE.'/index.php', 'PHP_SELF' => self::PREFIXE.'/index.php', 'SCRIPT_FILENAME' => $index];
        $_GET = $requete;
        $_POST = 'POST' === $methode ? $donnees : [];
        $_REQUEST = [...$_GET, ...$_POST];

        http_response_code(200);
        $niveau = ob_get_level();
        ob_start();
        try {
            (static function (string $fichier): void {
                require $fichier;
            })($index);
            $html = (string) ob_get_contents();
        } catch (\Throwable $erreur) {
            $this->fail(sprintf('The page %s crashed: %s (%s, line %d).', $url, $erreur->getMessage(), str_replace(self::projet().'/', '', $erreur->getFile()), $erreur->getLine()));
        } finally {
            while (ob_get_level() > $niveau) {
                ob_end_clean();
            }
            [$_SERVER, $_GET, $_POST, $_REQUEST] = $sauvegarde;
        }
        $statut = http_response_code();
        http_response_code(200);

        return self::$pages[$cle] = new Page($url, \is_int($statut) ? $statut : 200, $html);
    }

    /** The home page, which the first chapters test. */
    protected function accueil(): Page
    {
        return $this->visiter('/');
    }
}
