<?php

namespace App\Content\Check;

use App\Content\ContentRepository;
use App\Content\Exercise;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Un exercice de Pratique qui annonce une nouveauté (`version: '8.1'`) doit tourner sur un framework qui l'a :
 * sinon l'apprenant chercherait une fonctionnalité absente de son projet.
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
        $lock = json_decode((string) @file_get_contents((string) $environment->file('composer.lock')), true);
        $installed = null;
        foreach ($lock['packages'] ?? [] as $candidate) {
            if (($candidate['name'] ?? null) === $package) {
                $installed = (string) $candidate['version'];
            }
        }
        if (null === $installed) {
            $result->error(sprintf('« version: %s » : %s introuvable dans %s/composer.lock.', $version, $package, $environment->id));

            return;
        }

        if (Comparator::lessThan($this->parser->normalize($installed), $this->parser->normalize($version))) {
            $result->error(sprintf('La fonctionnalité arrive en %s, or l\'environnement « %s » a %s %s : mettez l\'environnement à jour.', $version, $environment->id, $package, $installed));
        }
    }
}
