<?php

namespace App\Content\Check;

use App\Content\ContentException;
use App\Content\Framework\FrameworkProfile;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** Le lanceur de tests d'un framework, choisi par FrameworkProfile::$testRunner. */
final readonly class TestRunners
{
    public function __construct(
        #[AutowireLocator(TestRunner::class)]
        private ContainerInterface $runners,
    ) {
    }

    public function for(FrameworkProfile $framework): TestRunner
    {
        if (!$this->runners->has($framework->testRunner)) {
            throw new ContentException(sprintf('Framework « %s » : aucun lanceur de tests « %s ».', $framework->id, $framework->testRunner));
        }

        return $this->runners->get($framework->testRunner);
    }
}
