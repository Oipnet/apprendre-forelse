<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Les refus de l'API écrits pour l'apprenant (« Confirmez votre adresse email… », débit dépassé, mentor absent…)
 * lui parviennent dans `detail`, comme en debug. Hors debug, Symfony n'y met que l'intitulé HTTP (« Precondition
 * Required ») : le playground affichait ce libellé à la place de l'explication.
 * Seulement les HttpException levées par notre code (src/) avec un message : celles du framework (routage,
 * convertisseurs) gardent leur réponse générique. Priorité -1 : après la journalisation de l'exception (0).
 */
final readonly class ApiErrorDetailListener
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/src/')]
        private string $sources,
    ) {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: -1)]
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof HttpExceptionInterface || '' === $exception->getMessage()
            || !str_starts_with($event->getRequest()->getPathInfo(), '/api/')
            || !str_starts_with($exception->getFile(), $this->sources)) {
            return;
        }

        $status = $exception->getStatusCode();
        $event->setResponse(new JsonResponse([
            'type' => 'https://tools.ietf.org/html/rfc2616#section-10',
            'title' => 'An error occurred',
            'status' => $status,
            'detail' => $exception->getMessage(),
        ], $status, ['Content-Type' => 'application/problem+json'] + $exception->getHeaders()));
    }
}
