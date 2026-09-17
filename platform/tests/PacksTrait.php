<?php

namespace App\Tests;

/**
 * Charge d'autres packs que ceux de .env pour un test : `usePacks()` avant de créer le client,
 * `restorePacks()` dans tearDown().
 */
trait PacksTrait
{
    private ?string $packsBefore = null;
    private bool $packsChanged = false;

    protected function usePacks(string ...$paths): void
    {
        if (!$this->packsChanged) {
            $this->packsBefore = $_SERVER['CONTENT_PACKS_PATHS'] ?? null;
            $this->packsChanged = true;
        }
        $_SERVER['CONTENT_PACKS_PATHS'] = $_ENV['CONTENT_PACKS_PATHS'] = implode(',', $paths);
    }

    /** Le pack de test « payant » : chapitre « libre » (e1), puis chapitre « complet » (e2, e3). */
    protected function usePaidPack(): void
    {
        $this->usePacks(__DIR__.'/Fixtures/packs/payant');
    }

    protected function restorePacks(): void
    {
        if (!$this->packsChanged) {
            return;
        }
        if (null === $this->packsBefore) {
            unset($_SERVER['CONTENT_PACKS_PATHS'], $_ENV['CONTENT_PACKS_PATHS']);
        } else {
            $_SERVER['CONTENT_PACKS_PATHS'] = $_ENV['CONTENT_PACKS_PATHS'] = $this->packsBefore;
        }
        $this->packsChanged = false;
    }
}
