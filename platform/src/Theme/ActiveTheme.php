<?php

namespace App\Theme;

use App\Entity\User;
use App\Repository\InstanceSettingRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Le thème servi par cette requête, choisi dans cet ordre :
 *
 * 1. l'aperçu d'un administrateur (sa session seulement, et seulement pour une lecture : un formulaire envoyé, donc
 *    un email parti, suit toujours le thème de tous) ;
 * 2. le thème activé depuis l'admin (réglage « theme », voir ThemeActivation) ;
 * 3. la variable THEME ;
 * 4. « instance » quand BRANDING_DIR contient un thème, sinon « default ».
 *
 * Un thème choisi qui n'est plus installé (dossier retiré) laisse la place à « default » : le site reste en ligne,
 * l'erreur part dans les journaux et l'admin l'affiche (voir missing()).
 *
 * Résolu une fois par requête, oublié entre deux (mode worker) : une bascule se voit à la requête suivante.
 */
#[AsAlias(ThemeSelection::class)]
final class ActiveTheme implements ThemeSelection, ResetInterface
{
    public const string SETTING = 'theme';

    public const string PREVIEW_SESSION = 'theme_preview';

    /** @var array{id: string, directory: string, preview: bool, missing: string|null}|null */
    private ?array $resolved = null;

    public function __construct(
        private readonly ThemeCatalog $catalog,
        private readonly InstanceSettingRepository $settings,
        private readonly RequestStack $requests,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'THEME')]
        private readonly string $configured = '',
    ) {
    }

    public function id(): string
    {
        return $this->resolve()['id'];
    }

    public function directory(): string
    {
        return $this->resolve()['directory'];
    }

    public function isPreview(): bool
    {
        return $this->resolve()['preview'];
    }

    /** Le thème choisi qui n'est plus installé, remplacé par « default » ; null quand tout va bien. */
    public function missing(): ?string
    {
        return $this->resolve()['missing'];
    }

    /** Le thème de tous, aperçu mis à part : celui que l'admin a activé, ou celui qui en tient lieu. */
    public function chosen(): string
    {
        $stored = $this->stored();
        if (null !== $stored) {
            return $stored;
        }
        if ('' !== $this->configured) {
            return $this->configured;
        }

        return $this->catalog->has(ThemeCatalog::INSTANCE) ? ThemeCatalog::INSTANCE : ThemeCatalog::DEFAULT;
    }

    public function reset(): void
    {
        $this->resolved = null;
    }

    /** @return array{id: string, directory: string, preview: bool, missing: string|null} */
    private function resolve(): array
    {
        if (null !== $this->resolved) {
            return $this->resolved;
        }
        $preview = $this->previewed();
        if (null !== $preview) {
            return $this->resolved = ['id' => $preview, 'directory' => (string) $this->catalog->directory($preview), 'preview' => true, 'missing' => null];
        }

        $id = $this->chosen();
        $directory = $this->catalog->directory($id);
        if (null === $directory) {
            $this->logger->error('Thème « {theme} » introuvable : le thème du moteur est servi à la place.', ['theme' => $id]);

            return $this->resolved = ['id' => ThemeCatalog::DEFAULT, 'directory' => '', 'preview' => false, 'missing' => $id];
        }

        return $this->resolved = ['id' => $id, 'directory' => $directory, 'preview' => false, 'missing' => null];
    }

    /**
     * Le réglage enregistré. Une base pas encore migrée (premier démarrage, cache:clear avant les migrations) ne doit
     * pas empêcher le site de s'afficher : on retombe alors sur THEME.
     */
    private function stored(): ?string
    {
        try {
            return $this->settings->setting(self::SETTING)?->getValue();
        } catch (\Doctrine\DBAL\Exception $e) {
            $this->logger->warning('Thème actif illisible en base ({message}) : THEME ou le thème par défaut est servi.', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Le thème qu'un administrateur prévisualise. La session n'est lue que si elle existe déjà : un visiteur anonyme
     * n'en ouvre pas une (sa page resterait en cache public, voir AnonymousPageCacheListener).
     */
    private function previewed(): ?string
    {
        $request = $this->requests->getMainRequest();
        if (null === $request || !$request->isMethodSafe() || !$request->hasPreviousSession()) {
            return null;
        }
        $preview = $request->getSession()->get(self::PREVIEW_SESSION);
        if (!\is_string($preview) || !$this->catalog->has($preview) || !$this->security->isGranted(User::ROLE_ADMIN)) {
            return null;
        }

        return $preview;
    }
}
