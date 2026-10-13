<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Point d'extension de Symfony (KernelTrait) : un APP_ENV hors de cette liste est refusé au démarrage.
     * Protégée, et non privée : l'appel vient du trait, et une méthode privée paraîtrait inutilisée.
     *
     * @return list<string> An array of allowed values for APP_ENV
     */
    protected function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }

    /**
     * La langue par défaut d'Intl (dates, nombres) : celle du site, et non celle du système (en_US_POSIX en ligne de
     * commande). Une requête la remplace ensuite par la sienne (Request::setLocale) ; une commande, un worker qui
     * envoie un e-mail, gardent celle-ci.
     */
    public function boot(): void
    {
        parent::boot();
        \Locale::setDefault($this->getContainer()->getParameter('kernel.default_locale'));
    }
}
