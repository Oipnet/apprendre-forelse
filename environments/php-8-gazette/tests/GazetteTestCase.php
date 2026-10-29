<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Le socle des tests cachés de la Gazette : visiter une page du projet comme le ferait le navigateur, sans serveur.
 *
 * public/index.php est exécuté dans le PHP des tests, avec les superglobales d'une vraie requête. Une même page n'est
 * exécutée qu'une fois par lancement (elle est gardée en mémoire) : avant le chapitre 3, index.php déclare ses
 * fonctions et ses classes, et le relire les redéclarerait. Visiter une seconde adresse demande donc un index.php qui
 * ne déclare rien, ce qui est le cas une fois les classes rangées dans src/.
 *
 * Le site est servi sous un préfixe (PREFIXE), comme dans l'aperçu de l'éditeur ou dans un sous-dossier d'un vrai
 * serveur : une adresse écrite en dur (« /articles/… ») y mène nulle part. visiter('/articles/x') demande donc
 * « /apercu/articles/x », et adresse('/articles/x') donne le lien que la page doit contenir.
 */
abstract class GazetteTestCase extends TestCase
{
    protected const string PREFIXE = '/apercu';

    /** @var array<string, Page> */
    private static array $pages = [];

    /** La racine du projet (là où vivent public/, src/ et composer.json). */
    protected static function projet(string $chemin = ''): string
    {
        return dirname(__DIR__).('' === $chemin ? '' : '/'.ltrim($chemin, '/'));
    }

    /** Le contenu d'un fichier du projet, « » s'il n'existe pas. */
    protected static function source(string $chemin): string
    {
        $fichier = self::projet($chemin);

        return is_file($fichier) ? (string) file_get_contents($fichier) : '';
    }

    /** Le lien qu'une page du site doit contenir pour mener à $chemin (« /articles/x » donne « /apercu/articles/x »). */
    protected static function adresse(string $chemin): string
    {
        return self::PREFIXE.'/'.ltrim($chemin, '/');
    }

    /** @param array<string, string> $donnees les champs d'un formulaire envoyé en POST */
    protected function visiter(string $url, string $methode = 'GET', array $donnees = []): Page
    {
        $cle = $methode.' '.$url.' '.json_encode($donnees);
        if (isset(self::$pages[$cle])) {
            return self::$pages[$cle];
        }
        $index = self::projet('public/index.php');
        $this->assertFileExists($index, 'Le fichier public/index.php est introuvable.');
        if ([] !== self::$pages && 1 === preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(function|class|enum|interface|trait)\s+\w+/mi', (string) file_get_contents($index))) {
            $this->fail('public/index.php déclare encore des fonctions ou des classes : rangez-les dans src/, sinon la page ne peut être chargée qu\'une fois.');
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
            $this->fail(sprintf('La page %s a planté : %s (%s, ligne %d).', $url, $erreur->getMessage(), str_replace(self::projet().'/', '', $erreur->getFile()), $erreur->getLine()));
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

    /** La page d'accueil, que les premiers chapitres testent. */
    protected function accueil(): Page
    {
        return $this->visiter('/');
    }
}
