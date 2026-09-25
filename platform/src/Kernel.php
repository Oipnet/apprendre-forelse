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
}
