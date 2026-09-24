<?php

namespace App\Twig;

use App\Security\ContentSecurityPolicy;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `{% do csp_allow_eval() %}` : la page embarque le simulateur Nuxt, qui compile le code de l'apprenant (voir ContentSecurityPolicy). */
final class ContentSecurityPolicyExtension extends AbstractExtension
{
    public function __construct(private readonly ContentSecurityPolicy $policy)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('csp_allow_eval', $this->policy->allowEval(...))];
    }
}
