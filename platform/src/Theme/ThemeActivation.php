<?php

namespace App\Theme;

use App\Repository\InstanceSettingRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Active un thème pour tout le monde, depuis l'admin ou app:theme:activer : après vérification seulement, et en
 * gardant la trace de qui l'a fait. La bascule se voit dès la requête suivante, sans redémarrer ni vider le cache.
 */
final readonly class ThemeActivation
{
    public function __construct(
        private ThemeChecker $checker,
        private InstanceSettingRepository $settings,
        private ActiveTheme $active,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string|null $by l'adresse de l'administrateur ; null pour une commande
     *
     * @throws ThemeActivationException
     */
    public function activate(string $id, ?string $by = null): void
    {
        $errors = $this->checker->errors($id);
        if ([] !== $errors) {
            throw ThemeActivationException::invalid($id, $errors);
        }
        $previous = $this->active->chosen();
        $this->settings->change(ActiveTheme::SETTING, $id, $this->clock->now(), $by);
        $this->active->reset();
        $this->logger->notice('Thème activé : « {theme} » (avant : « {previous} »), par {by}.', ['theme' => $id, 'previous' => $previous, 'by' => $by ?? 'une commande']);
    }
}
