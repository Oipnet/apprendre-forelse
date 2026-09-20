<?php

namespace App\Instance;

use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\PackEnvironment;

/**
 * Ce que les packs demandent, et ce que l'instance en a.
 *
 * Un pack déclare ses environnements dans `pack.yaml` (`environments: [{id, depot, ref}]`) ; le moteur
 * les installe s'ils manquent. C'est le prolongement de l'installation depuis un dépôt : là où un
 * administrateur collait une adresse, c'est le pack qui la porte — il sait mieux que lui de quel décor
 * ses exercices ont besoin.
 *
 * **Jamais pendant une requête web.** Installer, c'est cloner puis exécuter `composer install` : cela
 * dure des minutes et exécute du code. Ce service ne fait que lire, sauf quand on l'appelle depuis la
 * commande `app:environnement:synchroniser` ou depuis l'administration — c'est-à-dire depuis un geste
 * d'exploitation, jamais depuis la visite d'un apprenant.
 */
final readonly class PackEnvironments
{
    /** Le pack le demande et il est là : rien à faire. */
    public const string PRESENT = 'present';
    /** Le pack le demande, personne ne l'a : c'est ce que « synchroniser » installe. */
    public const string MISSING = 'missing';
    /** Installé depuis une autre adresse ou une autre référence que celle déclarée aujourd'hui. */
    public const string OUTDATED = 'outdated';
    /** Présent, mais pas venu d'un dépôt : livré par le moteur, ou installé à la main. Intouchable. */
    public const string FOREIGN = 'foreign';
    /** Une installation est en cours : on ne lance pas la même une seconde fois par-dessus. */
    public const string BUSY = 'busy';

    public function __construct(
        private ContentRepository $content,
        private EnvironmentRegistry $environments,
        private InstalledEnvironments $installed,
        private EnvironmentInstaller $installer,
        private EnvironmentArtifacts $artifacts,
    ) {
    }

    /**
     * Les environnements que les packs **portent** (`<pack>/environments/<id>/`) et dont l'archive
     * manque encore.
     *
     * Rien à cloner ici : les fichiers sont arrivés avec le pack. Il reste à les empaqueter — composer
     * install, archive, index de complétion —, ce qui est exactement la seconde moitié d'une
     * installation depuis un dépôt.
     *
     * @return list<string> identifiants, dans l'ordre alphabétique
     */
    public function toBuild(): array
    {
        $aFaire = [];
        foreach ($this->environments->carried() as $id => $directory) {
            if (null === $this->artifacts->path($id.'.zip')) {
                $aFaire[] = $id;
            }
        }
        sort($aFaire);

        return $aFaire;
    }

    /**
     * L'état de chaque environnement demandé par un pack, dans l'ordre alphabétique.
     *
     * @return list<array{environment: PackEnvironment, state: string, source: InstalledEnvironment|null}>
     */
    public function state(): array
    {
        $installes = $this->installed->all();
        $lignes = [];
        foreach ($this->content->packEnvironments() as $wanted) {
            $source = $installes[$wanted->id] ?? null;
            $lignes[] = [
                'environment' => $wanted,
                'state' => $this->stateOf($wanted, $source),
                'source' => $source,
            ];
        }

        return $lignes;
    }

    /**
     * Ce qu'il faudrait installer pour que les packs tournent.
     *
     * @param bool $update inclure ceux qui viennent d'un dépôt mais d'une autre adresse ou référence
     *
     * @return list<PackEnvironment>
     */
    public function toInstall(bool $update = false): array
    {
        $aFaire = [];
        foreach ($this->state() as $ligne) {
            if (self::MISSING === $ligne['state'] || ($update && self::OUTDATED === $ligne['state'])) {
                $aFaire[] = $ligne['environment'];
            }
        }

        return $aFaire;
    }

    /**
     * Installe ce qui manque. Rend ce qui a été fait, et ce qui a échoué.
     *
     * Un échec n'arrête pas les autres : trois packs dont un dépôt est injoignable, c'est deux
     * environnements installés et un message, pas rien du tout.
     *
     * **Plusieurs passes**, et c'est nécessaire : un environnement peut en prolonger un autre
     * (`extends:`), et on ne l'apprend qu'après l'avoir cloné — c'est écrit dans son environment.yaml,
     * pas dans la déclaration du pack. Impossible, donc, de trier à l'avance. Tant qu'une passe en
     * installe au moins un, on rejoue ceux qui ont échoué : la base qui manquait est peut-être arrivée
     * entre-temps. Rien de neuf installé, on s'arrête, et les échecs restants sont de vrais échecs.
     *
     * @param callable(string): void|null $progress
     *
     * @return array{installed: list<string>, failed: array<string, string>}
     */
    public function synchronize(bool $update = false, ?callable $progress = null): array
    {
        $say = $progress ?? static fn (string $message) => null;
        $installed = [];
        $failed = [];
        $restants = $this->toInstall($update);

        while ([] !== $restants) {
            $echoues = [];
            $failed = [];
            foreach ($restants as $wanted) {
                $say(sprintf('Pack « %s » : environnement « %s » depuis %s', $wanted->packId, $wanted->id, $wanted->describeSource()));
                try {
                    $installed[] = $this->installOne($wanted, $say);
                } catch (\Throwable $e) {
                    $echoues[] = $wanted;
                    $failed[$wanted->id] = $e->getMessage();
                }
            }
            // Aucun progrès dans cette passe : rejouer les mêmes donnerait les mêmes erreurs.
            if (\count($echoues) === \count($restants)) {
                break;
            }
            $restants = $echoues;
            if ([] !== $restants) {
                $say(sprintf('Nouvelle passe pour %d environnement(s) : leur base vient peut-être d\'être installée.', \count($restants)));
            }
        }

        // Puis les environnements que les packs portent : rien à cloner, seulement à empaqueter. Après
        // les clonages, pour qu'un environnement porté puisse prolonger un environnement tout juste
        // installé — et l'inverse vaut par les passes ci-dessus.
        if ([] !== $installed) {
            $this->environments->reset();
        }
        foreach ($this->toBuild() as $id) {
            $say(sprintf('Environnement « %s », porté par un pack : empaquetage…', $id));
            try {
                $this->installer->build($id);
                $installed[] = $id;
            } catch (\Throwable $e) {
                $failed[$id] = $e->getMessage();
            }
        }

        if ([] !== $installed) {
            $this->environments->reset();
            $this->content->reset();
        }

        return ['installed' => $installed, 'failed' => $failed];
    }

    /** @param callable(string): void $say */
    private function installOne(PackEnvironment $wanted, callable $say): string
    {
        $obtenu = $this->installer->install($wanted->depot, $wanted->ref, $wanted->dossier, $say);
        // Le dépôt se nomme lui-même : s'il ne porte pas le nom attendu, le pack ne trouvera toujours
        // pas son décor. Mieux vaut le dire que laisser un environnement orphelin.
        if ($obtenu !== $wanted->id) {
            throw new ContentException(sprintf('le dépôt fournit l\'environnement « %s », alors que le pack « %s » attend « %s ». « %s » est installé, mais le pack ne le trouvera pas : alignez l\'« id » de l\'environment.yaml du dépôt, ou celui que le pack déclare.', $obtenu, $wanted->packId, $wanted->id, $obtenu));
        }

        return $obtenu;
    }

    private function stateOf(PackEnvironment $wanted, ?InstalledEnvironment $source): string
    {
        if (null === $source) {
            return $this->environments->has($wanted->id) ? self::FOREIGN : self::MISSING;
        }
        // Une installation en cours se laisse finir : deux « composer install » dans le même dossier
        // s'abîmeraient l'un l'autre. L'administration montre où elle en est.
        if (InstalledEnvironment::INSTALLING === $source->state) {
            return self::BUSY;
        }
        // Une installation ratée n'a rien laissé de jouable : elle se rejoue comme si rien n'était là.
        if ($source->hasFailed()) {
            return self::MISSING;
        }

        return $source->url === $wanted->depot && $source->ref === $wanted->ref && $source->dossier === $wanted->dossier
            ? self::PRESENT
            : self::OUTDATED;
    }
}
