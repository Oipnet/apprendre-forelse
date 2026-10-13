<?php

declare(strict_types=1);

namespace Forelse\DockerSim;

/**
 * Les textes du simulateur montrés à l'apprenant : remarques (💡, « (simulateur : …) »), notes du build, pages
 * d'erreur du navigateur, traces de l'aperçu, messages d'échec des tests (DockerTestCase).
 *
 * Palier 1 de l'internationalisation : seul le français existe. Une valeur est un format de sprintf() ; une phrase
 * reste entière dans un seul message. Les sorties qui imitent un outil réel (docker, BuildKit, nginx, Apache,
 * Composer, psql, le shell…) n'y sont pas : elles restent celles de l'outil imité. Une autre langue s'ajoute à
 * CATALOGUES, avec les mêmes clés ; une clé qui lui manque retombe sur le français.
 */
final class Messages
{
    public const LANGUE_PAR_DEFAUT = 'fr';

    private const CATALOGUES = [
        'fr' => [
            // --- Console docker (Cli) ---------------------------------------------------------------
            'cli.only_docker' => "💡 Cette console ne comprend que les commandes docker. Pour « %s », deux chemins : l'explorateur de fichiers à gauche pour votre projet, ou « docker exec <conteneur> %s » pour l'intérieur d'un conteneur.",
            'cli.login' => '(simulateur : aucun registre n\'est contacté)',
            'cli.init' => "docker init : l'assistant interactif n'est pas disponible dans le simulateur.\nÉcrivez le Dockerfile et le compose.yaml à la main : c'est l'objet du parcours.",
            'cli.stats' => '(simulateur : pas de mesure de CPU ni de mémoire)',
            'cli.not_simulated' => 'docker %s : commande non simulée.',
            'cli.help_banner' => '(simulateur forelse : tout se passe dans votre navigateur)',
            'cli.help_unavailable' => '(aide détaillée non disponible dans le simulateur : voir https://docs.docker.com/reference/cli/docker/%s/)',
            'cli.version_server' => '(simulateur forelse)',
            'cli.info_storage' => '(simulé)',
            'cli.info_os' => 'Simulateur Docker (forelse), dans votre navigateur',
            'cli.build_untagged' => 'L\'image n\'a pas de nom : ajoutez -t nom pour la retrouver dans docker images.',
            'cli.push_no_registry' => '💡 Le simulateur n\'a pas de registre : docker push ne peut pas publier l\'image.',
            'cli.run_no_tty' => '💡 La console du simulateur n\'est pas un terminal : impossible d\'entrer dans le conteneur. Il tourne en arrière-plan (%s) ; lancez vos commandes avec docker exec %s <commande>.',
            'cli.run_detached' => '💡 Le conteneur %s tourne. Un vrai terminal resterait attaché à ses journaux (Ctrl+C l\'arrêterait) ; ici la main vous est rendue et il continue en arrière-plan : docker ps, docker logs %s, docker stop %s.',
            'cli.logs_follow' => '💡 (simulateur : -f ne suit pas les journaux en continu ; relancez la commande pour voir les nouvelles lignes)',
            'cli.exec_no_tty' => '💡 La console du simulateur n\'est pas un terminal : pas de session interactive. Passez la commande directement, par exemple : docker exec %s %s',
            'cli.script_empty' => "💡 Le script ne contient encore aucune commande docker (une par ligne, par exemple : docker run …).\n",
            'cli.script_comment' => "💡 « %s » n'est pas une commande docker : le simulateur l'ignore.\n",

            // --- docker compose -------------------------------------------------------------------
            'compose.watch' => 'docker compose watch : non disponible dans le simulateur (utilisez un volume monté pour voir vos modifications sans reconstruire).',
            'compose.up_attached' => "💡 Un vrai terminal resterait attaché aux journaux (Ctrl+C arrêterait tout). Ici la main vous est rendue et les conteneurs continuent de tourner : docker compose ps, docker compose logs, docker compose down.\n",
            'compose.exec_no_tty' => "💡 La console du simulateur n'est pas un terminal : pas de session interactive. Passez la commande directement : docker compose exec %s sh -c \"ls -la\"\n",
            'compose.run_detached' => '(simulateur : le conteneur %s continue de tourner en arrière-plan, la console ne peut pas s\'y attacher)',

            // --- Build et shell des conteneurs (notes et remarques) --------------------------------
            'build.copy_outside_context' => 'COPY ne peut rien prendre en dehors du contexte de build (le dossier passé à docker build).',
            'build.dockerignored' => '« %s » est exclu par le .dockerignore : il n\'est pas envoyé au démon.',
            'build.downloaded_file' => "# téléchargé depuis %s (simulé)\n",
            'build.base_history' => 'installation des paquets de l\'image',
            'shell.not_simulated' => '« %s » n\'est pas simulé : la commande est considérée comme réussie.',
            'shell.loop_stopped' => 'Boucle interrompue par le simulateur au bout de 30 tours.',
            'shell.archives' => '« %s » : les archives ne sont pas simulées (commande considérée comme réussie).',
            'shell.find_end' => '(fin de l\'expression)',
            'shell.no_tty' => "%s: pas de terminal interactif dans le simulateur (utilisez cat, sed ou echo)\n",
            'shell.downloaded_content' => "# contenu téléchargé depuis %s (simulé)\n",
            'shell.git_clone' => 'git clone : le simulateur ne télécharge pas de dépôt ; copiez le code depuis le contexte de build (COPY).',
            'shell.psql_interactive' => '(le simulateur n\'ouvre pas de session interactive : utilisez -c "...")',
            'shell.sql_not_replayed' => "(le simulateur ne rejoue pas les requêtes SQL : seule la connexion est vérifiée)\n",
            'shell.redis_interactive' => '(session interactive non simulée)',
            'shell.apk_closest' => 'Le paquet Alpine le plus proche de « %s » est « %s ».',
            'shell.composer_no_packagist' => 'le simulateur n\'a pas accès à Packagist.',
            'shell.composer_copy_lock' => 'Copiez aussi composer.lock dans l\'image : c\'est lui qui fige les versions.',
            'shell.composer_unzip' => 'Composer a besoin de unzip (ou de l\'extension zip) pour décompresser les paquets, ou de git pour les cloner.',
            'shell.php_ext_missing_package' => 'L\'extension %s a besoin du paquet %s pour compiler.',
            'shell.pecl_phpize' => 'pecl compile les extensions : sur Alpine, installez d\'abord $PHPIZE_DEPS (apk add --no-cache $PHPIZE_DEPS).',
            'shell.php_script_skipped' => 'php %s : le script n\'est pas exécuté pendant un build (considéré comme réussi).',
            'shell.composer_installer' => "<?php // installateur de Composer (getcomposer.org)\n",
            'shell.php_installer' => "<?php // installateur téléchargé depuis %s\n",
            'shell.php_eval_limited' => 'php -r : seules quelques expressions simples sont simulées pendant un build.',
            'process.worker' => '(simulateur : le processus tourne en continu, les messages ne sont pas traités)',

            // --- Pages des images du catalogue (ce que montre l'aperçu) ----------------------------
            'catalog.mail_inbox' => 'Boîte de réception de test — aucun message pour le moment.',
            'catalog.adminer' => 'Connexion à la base de données (simulateur : formulaire non fonctionnel).',
            'catalog.phpmyadmin' => 'Bienvenue dans phpMyAdmin (simulateur : page d\'accueil seulement).',
            'catalog.caddy' => 'Page par défaut de l\'image caddy.',
            'catalog.rabbitmq' => 'Interface de gestion (simulateur).',
            'catalog.traefik' => 'Tableau de bord (simulateur).',

            // --- Aperçu : connexions depuis l'hôte et pages d'erreur du navigateur ------------------
            'http.host_only_localhost' => '« %s » : seul localhost désigne la machine hôte (les noms des conteneurs ne sont connus qu\'à l\'intérieur de leur réseau).',
            'http.port_not_listening' => 'Le port %d de l\'hôte mène au port %d du conteneur %s, mais rien n\'y écoute (le processus écoute sur : %s).',
            'http.no_port' => 'aucun port',
            'http.listens_on_loopback' => 'Le serveur du conteneur %s écoute sur 127.0.0.1:%d : il n\'accepte que les connexions venues de l\'intérieur du conteneur. Écoutez sur 0.0.0.0.',
            'http.port_not_published' => 'Aucun conteneur en cours d\'exécution ne publie le port %d de l\'hôte.',
            'http.php_fpm_not_http' => 'php-fpm (%s) parle FastCGI, pas HTTP : il faut un serveur web (nginx) devant lui.',
            'http.not_a_web_server' => '%s (%s) n\'est pas un serveur web.',
            'http.nothing_listening' => 'Rien n\'écoute sur le port %d du conteneur %s.',
            'browser.unreachable' => 'Ce site est inaccessible',
            'browser.not_working' => 'Cette page ne fonctionne pas',
            'browser.refused' => '<strong>%s</strong> n\'autorise pas la connexion.',
            'browser.reset' => 'La connexion a été réinitialisée.',
            'browser.empty' => '<strong>%s</strong> n\'a envoyé aucune donnée.',
            'browser.unresolved' => 'Impossible de trouver l\'adresse IP du serveur de <strong>%s</strong>.',
            'browser.details' => 'Ce que voit le simulateur',

            // --- Aperçu : la trace du chemin d'une requête (serveurs web, réseau des conteneurs) ----
            'trace.unknown_host' => 'nom inconnu : %s',
            'trace.connection_refused' => 'connexion refusée : %s:%d',
            'trace.unknown_name' => 'nom inconnu',
            'trace.refused' => 'connexion refusée',
            'trace.no_listing' => 'Options -Indexes : pas de liste de fichiers',
            'trace.rewritten' => 'réécrit vers %s (%s)',
            'trace.file_not_found' => 'fichier introuvable : %s',
            'trace.not_found' => 'introuvable : %s',
            'trace.static_file' => 'fichier statique : %s',
            'trace.static_php' => ' (servi tel quel : aucune location ne le passe à PHP)',
            'trace.php_script' => 'script PHP : %s',
            'trace.nginx_not_listening' => 'nginx (%s) n\'écoute pas sur le port %d',
            'trace.no_location' => '(aucune)',
            'trace.denied' => 'deny : accès refusé par une règle',
            'trace.try_files' => 'try_files : aucun fichier, %s',
            'trace.no_index' => 'pas de fichier index dans %s',
            'trace.not_fastcgi' => 'fastcgi_pass %s : %s ne parle pas FastCGI',
            'trace.this_process' => 'ce processus',
            'trace.no_script_filename' => 'fastcgi_param SCRIPT_FILENAME absent : php-fpm ne sait pas quel script lancer',
            'trace.script_missing' => 'php-fpm (%s) : %s n\'existe pas dans ce conteneur',

            // --- Tests d'exercice (DockerTestCase) : messages d'échec -------------------------------
            'test.file_missing' => 'Le fichier %s n\'existe pas.',
            'test.script_empty' => '%s ne contient aucune commande docker.',
            'test.script_failed' => "Une commande de %s a échoué (code %d) :\n%s",
            'test.docker_failed' => "« docker %s » a échoué (code %d) :\n%s",
            'test.no_container' => 'Aucun conteneur « %s ».',
            'test.no_service_container' => 'Le service « %s » n\'a pas de conteneur (docker compose up a-t-il réussi ?).',
            'test.no_image' => 'Aucune image « %s ».',
            'test.build_failed' => "Le build a échoué :\n%s",
            'test.build_should_fail' => 'Le build aurait dû échouer.',
            'test.image_lacks_file' => 'L\'image ne contient pas %s.',
            'test.image_has_file' => 'L\'image contient %s, qui ne devrait pas y être.',
            'test.image_too_big' => 'L\'image pèse %d Mo : elle devrait rester sous %d Mo.',
            'test.not_running' => "Le conteneur %s ne tourne pas (%s, code %d). Ses journaux :\n%s",
            'test.no_response' => '%s ne répond pas : %s',
            'test.page_lacks_text' => "La page %s (HTTP %d) ne contient pas « %s ».\n%s",
        ],
    ];

    private static string $langue = self::LANGUE_PAR_DEFAUT;

    /** Choisit la langue des textes suivants (« fr », « fr-FR »…) ; une langue absente retombe sur le français. */
    public static function choisirLangue(string $langue): void
    {
        self::$langue = self::langueConnue($langue);
    }

    /** La langue des textes : celle d'un attribut lang, par exemple. */
    public static function langue(): string
    {
        return self::$langue;
    }

    /**
     * Le texte d'une clé, ses paramètres insérés (sprintf), dans la langue donnée, ou celle choisie par défaut.
     *
     * @param list<string|int|float> $parametres
     */
    public static function texte(string $cle, array $parametres = [], ?string $langue = null): string
    {
        $langue = $langue === null ? self::$langue : self::langueConnue($langue);
        $format = self::CATALOGUES[$langue][$cle] ?? self::CATALOGUES[self::LANGUE_PAR_DEFAUT][$cle] ?? throw new \LogicException(sprintf('Message inconnu : %s', $cle));

        return $parametres === [] ? $format : sprintf($format, ...$parametres);
    }

    /** @return array<string, string> */
    public static function catalogue(string $langue = self::LANGUE_PAR_DEFAUT): array
    {
        return self::CATALOGUES[self::langueConnue($langue)];
    }

    private static function langueConnue(string $langue): string
    {
        $langue = strtolower(explode('-', trim($langue))[0]);

        return isset(self::CATALOGUES[$langue]) ? $langue : self::LANGUE_PAR_DEFAUT;
    }
}
