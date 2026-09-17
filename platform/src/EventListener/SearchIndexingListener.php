<?php

namespace App\EventListener;

use App\Seo\SearchIndexing;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * En-tête X-Robots-Tag sur toute réponse qui ne doit pas finir dans un moteur de recherche : il couvre aussi ce que
 * les gabarits ne rendent pas (PDF, JSON, EasyAdmin, bac à sable). Voir SearchIndexing pour la règle.
 */
final readonly class SearchIndexingListener
{
    public function __construct(private SearchIndexing $indexing)
    {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $directive = $this->indexing->directive($event->getRequest(), $event->getResponse()->getStatusCode());
        if (null !== $directive) {
            $event->getResponse()->headers->set('X-Robots-Tag', $directive);
        }
    }
}
