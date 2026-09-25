<?php

namespace App\Tests\Monitoring;

use App\Monitoring\FiltreSentry;
use App\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

final class FiltreSentryTest extends TestCase
{
    /** @return iterable<string, array{\Throwable}> */
    public static function reponsesNormales(): iterable
    {
        yield 'page introuvable' => [new NotFoundHttpException()];
        yield 'requête invalide' => [new BadRequestHttpException()];
        yield 'accès refusé' => [new AccessDeniedException()];
        yield 'mauvais mot de passe' => [new BadCredentialsException()];
    }

    #[DataProvider('reponsesNormales')]
    public function testUneReponseNormaleNePartPas(\Throwable $exception): void
    {
        self::assertNull($this->filtre()(Event::createEvent(), EventHint::fromArray(['exception' => $exception])));
    }

    public function testUnePanneDuServeurPartAvecLaVersion(): void
    {
        foreach ([new \RuntimeException('panne'), new ServiceUnavailableHttpException()] as $exception) {
            $event = $this->filtre()(Event::createEvent(), EventHint::fromArray(['exception' => $exception]));

            self::assertNotNull($event);
            self::assertSame(trim((string) file_get_contents(__DIR__.'/../../../VERSION')), $event->getRelease());
        }
    }

    public function testUnMessageSansExceptionPart(): void
    {
        self::assertNotNull($this->filtre()(Event::createEvent(), null));
    }

    private function filtre(): FiltreSentry
    {
        return new FiltreSentry(new Version(__DIR__.'/../../../VERSION'));
    }
}
