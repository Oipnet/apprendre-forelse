<?php

namespace App\Content\Check;

use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Content\Framework\FrameworkProfile;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Un exercice de Pratique qui annonce une nouveauté (`version: '8.1'`) doit tourner sur un framework qui l'a :
 * sinon l'apprenant chercherait une fonctionnalité absente de son projet. Sans framework, la version est celle de PHP,
 * que l'environnement déclare (clé « php »).
 */
#[AsTaggedItem(priority: 20)]
final readonly class PracticeVersionCheck implements ExerciseCheck
{
    public function __construct(
        private ContentRepository $content,
        private VersionParser $parser = new VersionParser(),
    ) {
    }

    public function check(Exercise $exercise, CheckContext $context, CheckResult $result): void
    {
        $version = null === $exercise->trackId ? $this->content->findPractice($exercise->id)?->version : null;
        if (null === $version) {
            return;
        }
        $environment = $context->environment;
        // Le paquet dont la version fait foi est déclaré par le profil ; certains n'en ont pas (Docker, Nuxt).
        $package = $environment->framework->versionPackage;
        if (null === $package) {
            $result->error(sprintf('« version » n\'a pas de sens pour l\'environnement « %s » (%s) : retirez-la.', $environment->id, $environment->framework->label));

            return;
        }
        $installed = FrameworkProfile::PHP_VERSION === $package ? ('' !== $environment->phpVersion ? $environment->phpVersion : null) : $this->locked($environment->file('composer.lock'), $package);
        if (null === $installed) {
            $result->error(FrameworkProfile::PHP_VERSION === $package
                ? sprintf('« version: %s » : l\'environnement « %s » ne déclare pas sa version de PHP (clé « php »).', $version, $environment->id)
                : sprintf('« version: %s » : %s introuvable dans %s/composer.lock.', $version, $package, $environment->id));

            return;
        }

        if (!self::reaches($this->parser, $installed, $version)) {
            $result->error(sprintf('La fonctionnalité arrive en %s, or l\'environnement « %s » a %s %s : mettez l\'environnement à jour.', $version, $environment->id, $package, $installed));
        }
    }

    /**
     * La version installée apporte-t-elle les nouveautés de la version annoncée ? Une préversion (alpha, bêta, RC)
     * de 8.2 les apporte déjà : c'est pour elle que les exercices sur 8.2 sortent avant la version finale.
     */
    public static function reaches(VersionParser $parser, string $installed, string $announced): bool
    {
        $normalized = (string) preg_replace('/-(alpha|beta|rc)\d*$/i', '', $parser->normalize($installed));

        return !Comparator::lessThan($normalized, $parser->normalize($announced));
    }

    private function locked(?string $lockFile, string $package): ?string
    {
        $lock = json_decode((string) @file_get_contents((string) $lockFile), true);
        $installed = null;
        foreach ($lock['packages'] ?? [] as $candidate) {
            if (($candidate['name'] ?? null) === $package) {
                $installed = (string) $candidate['version'];
            }
        }

        return $installed;
    }
}
