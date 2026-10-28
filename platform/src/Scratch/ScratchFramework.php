<?php

namespace App\Scratch;

/**
 * Les frameworks du bac à sable (et PHP sans framework) : un projet vierge, sans consigne ni test, où l'on essaie du code et dont on partage
 * un lien. Chacun tourne sur un environnement d'exécution existant (environments/).
 *
 * Pas de Nuxt : le code d'un projet Nuxt s'exécute en partie sur l'origine de la plateforme (worker du simulateur),
 * et un lien partagé ferait tourner le code d'un inconnu. Le PHP, lui, reste dans WebAssembly et l'aperçu sur
 * l'origine du bac à sable.
 */
enum ScratchFramework: string
{
    case Symfony = 'symfony';
    case Laravel = 'laravel';
    case Php = 'php';

    /** L'environnement d'exécution du projet (environments/<id>). */
    public function environment(): string
    {
        return match ($this) {
            self::Symfony => 'symfony-8',
            self::Laravel => 'laravel-13',
            self::Php => 'php-8',
        };
    }

    /** Le fichier ouvert à l'arrivée. */
    public function open(): string
    {
        return match ($this) {
            self::Symfony => 'src/Controller/AccueilController.php',
            self::Laravel => 'routes/web.php',
            self::Php => 'public/index.php',
        };
    }

    /**
     * Ce que le visiteur peut modifier et créer. Un `*` ne franchit pas un « / » (voir Exercise::isEditable) : le
     * code de l'application et ses vues, pas la configuration du framework, qu'un lien partagé n'a pas à changer.
     *
     * @return list<string>
     */
    public function editable(): array
    {
        return match ($this) {
            self::Symfony => [
                'src/Controller/AccueilController.php',
                'templates/accueil.html.twig',
                'src/*/*.php',
                'templates/*.html.twig',
                'templates/*/*.html.twig',
            ],
            self::Laravel => [
                'routes/web.php',
                'resources/views/accueil.blade.php',
                'app/*/*.php',
                'app/Http/Controllers/*.php',
                'resources/views/*.blade.php',
                'resources/views/*/*.blade.php',
            ],
            self::Php => [
                'public/index.php',
                'templates/accueil.php',
                'public/*.php',
                'src/*.php',
                'src/*/*.php',
                'templates/*.php',
                'templates/*/*.php',
                'bin/*.php',
            ],
        };
    }

    /** Le nom affiché (titres, liens). */
    public function label(): string
    {
        return match ($this) {
            self::Symfony => 'Symfony',
            self::Laravel => 'Laravel',
            self::Php => 'PHP',
        };
    }
}
