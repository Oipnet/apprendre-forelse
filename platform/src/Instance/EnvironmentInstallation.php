<?php

namespace App\Instance;

use App\Content\ContentException;
use App\Content\EnvironmentRegistry;

/**
 * Ce que l'administration fait des environnements installés depuis un dépôt Git : les installer, les mettre à jour,
 * empaqueter ceux que les packs portent, les retirer. L'installation dure des minutes (clone, `composer install`,
 * archive) : elle part en tâche de fond, et son état vit dans le dossier de l'environnement.
 */
final readonly class EnvironmentInstallation
{
    private const string INSTALL = 'app:environnement:installer';
    private const string SYNC = 'app:environnement:synchroniser';

    public function __construct(
        private EnvironmentRegistry $environments,
        private InstalledEnvironments $installed,
        private EnvironmentJobLauncher $launcher,
    ) {
    }

    /**
     * @param string $ref    branche, étiquette ou commit ; vide : la branche par défaut
     * @param string $folder sous-dossier du dépôt qui contient l'environnement ; vide : la racine
     *
     * @throws ContentException dossier des environnements indisponible, ou adresse de dépôt refusée
     */
    public function install(string $repository, string $ref, string $folder): void
    {
        $this->assertEnabled();
        $repository = trim($repository);
        if (!str_starts_with($repository, 'https://')) {
            throw new ContentException('L\'adresse du dépôt doit commencer par « https:// ».');
        }
        $ref = trim($ref);
        $folder = trim($folder);
        $this->launcher->launch(self::INSTALL, [
            $repository,
            ...('' === $ref ? [] : ['--ref='.$ref]),
            ...('' === $folder ? [] : ['--dossier='.$folder]),
        ]);
    }

    /** @throws ContentException identifiant d'environnement invalide */
    public function update(string $id): void
    {
        $this->launcher->launch(self::INSTALL, [InstalledEnvironments::id($id)]);
    }

    /**
     * Empaquette d'un coup les environnements que les packs portent et qui n'ont pas d'archive.
     *
     * @throws ContentException dossier des environnements indisponible
     */
    public function syncPackEnvironments(): void
    {
        $this->assertEnabled();
        $this->launcher->launch(self::SYNC, []);
    }

    /**
     * Irréversible : le dossier cloné et ses archives sont supprimés, et le moteur cesse aussitôt de le charger.
     *
     * @throws ContentException identifiant d'environnement invalide
     */
    public function remove(string $id): void
    {
        $this->installed->remove($id);
        $this->environments->reset();
    }

    /**
     * Les environnements que le moteur sait charger, avec leur source quand ils viennent d'un dépôt.
     *
     * @return list<array{id: string, title: string, framework: string, compose: bool, source: InstalledEnvironment|null}>
     */
    public function available(): array
    {
        $installed = $this->installed->all();
        $rows = [];
        foreach ($this->environments->all() as $id => $environment) {
            $rows[] = [
                'id' => $id,
                'title' => $environment->title,
                'framework' => $environment->framework->label,
                'compose' => $environment->isComposed(),
                'source' => $installed[$id] ?? null,
            ];
        }

        return $rows;
    }

    private function assertEnabled(): void
    {
        if (!$this->installed->isEnabled()) {
            throw new ContentException(sprintf('Aucun dossier d\'environnements installables : %s n\'existe pas ou n\'est pas écrivable.', $this->installed->directory()));
        }
    }
}
