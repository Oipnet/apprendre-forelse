<?php

namespace App\Tests;

use App\Content\Check\CompletionIndexCheck;
use App\Content\Check\ExerciseChecker;
use App\Content\Check\MutantGrader;
use App\Content\Check\NodeModuleRunner;
use App\Content\Check\PhpunitRunner;
use App\Content\Check\PracticeVersionCheck;
use App\Content\Check\ProcessEnvironment;
use App\Content\Check\ProjectWorkdir;
use App\Content\Check\StructureCheck;
use App\Content\Check\TestRunners;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\Framework\FrameworkProfile;
use App\Instance\EnvironmentArtifacts;
use App\Instance\InstalledEnvironments;
use Symfony\Component\DependencyInjection\ServiceLocator;

/** Un ExerciseChecker monté hors du conteneur, comme le conteneur le monte : ses lanceurs et ses vérifications. */
trait ExerciseCheckerTrait
{
    protected static function exerciseChecker(ContentRepository $content, EnvironmentRegistry $environments, string $platformDir = __DIR__.'/..', ?string $artifactsDir = null): ExerciseChecker
    {
        $workdirs = new ProjectWorkdir();
        $runners = new TestRunners(new ServiceLocator([
            FrameworkProfile::PHPUNIT => static fn () => new PhpunitRunner(new ProcessEnvironment($platformDir)),
            FrameworkProfile::VITEST => static fn () => new NodeModuleRunner(new ProcessEnvironment($platformDir), $platformDir),
        ]));
        $artifacts = new EnvironmentArtifacts($artifactsDir ?? $platformDir.'/public/envs', new InstalledEnvironments(''));

        return new ExerciseChecker(
            $content,
            $environments,
            $runners,
            new MutantGrader($workdirs),
            [new StructureCheck(), new PracticeVersionCheck($content), new CompletionIndexCheck($artifacts)],
            $workdirs,
        );
    }
}
