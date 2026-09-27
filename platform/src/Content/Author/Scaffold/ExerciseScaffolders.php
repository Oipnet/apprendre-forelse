<?php

namespace App\Content\Author\Scaffold;

use App\Content\ContentException;
use App\Content\Framework\FrameworkProfile;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** Les échafaudeurs connus, par identifiant de framework. */
final class ExerciseScaffolders
{
    public function __construct(
        #[AutowireLocator(ExerciseScaffolder::TAG, defaultIndexMethod: 'framework')]
        private readonly ContainerInterface $locator,
    ) {
    }

    public function has(FrameworkProfile $framework): bool
    {
        return $this->locator->has($framework->id);
    }

    public function get(FrameworkProfile $framework): ExerciseScaffolder
    {
        if (!$this->has($framework)) {
            throw new ContentException(sprintf('L\'atelier ne sait pas encore créer un exercice %s : écrivez-le à la main dans le pack.', $framework->label));
        }

        return $this->locator->get($framework->id);
    }
}
