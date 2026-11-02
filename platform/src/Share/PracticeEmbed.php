<?php

namespace App\Share;

use App\Content\Framework\FrameworkRegistry;
use App\Content\Practice;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Un exercice de la Pratique sur un autre site (blog, support de cours, documentation) : une carte en iframe
 * (/integrer/pratique/<id>, la seule page que n'importe quel site peut encadrer) et, dessous, un lien vers
 * l'exercice. La carte n'exécute rien : son bouton ouvre l'exercice ici, dans un nouvel onglet. Le lien, lui, est
 * un vrai lien de la page hôte : celui que les moteurs de recherche suivent.
 */
final readonly class PracticeEmbed
{
    /** La source des visites venues d'une carte intégrée, dans la mesure d'audience. */
    public const array UTM = ['utm_source' => 'integration', 'utm_medium' => 'iframe'];

    public function __construct(
        private FrameworkRegistry $frameworks,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private SiteHost $site,
    ) {
    }

    /** Le code HTML à coller sur l'autre site. */
    public function code(Practice $practice): string
    {
        $title = htmlspecialchars($practice->exercise->title, \ENT_QUOTES);
        $src = htmlspecialchars($this->urls->generate('app_practice_embed', ['exerciseId' => $practice->exercise->id], UrlGeneratorInterface::ABSOLUTE_URL), \ENT_QUOTES);
        $href = htmlspecialchars($this->urls->generate('app_exercise_pratique', ['exerciseId' => $practice->exercise->id], UrlGeneratorInterface::ABSOLUTE_URL), \ENT_QUOTES);
        $line = $this->translator->trans('share.integration.lien', [
            'lien' => \sprintf('<a href="%s">%s</a>', $href, $title),
            'framework' => htmlspecialchars($this->framework($practice), \ENT_QUOTES),
            'site' => htmlspecialchars($this->site->name(), \ENT_QUOTES),
        ]);

        return \sprintf('<iframe src="%s" title="%s" width="100%%" height="320" style="max-width:640px;border:0" loading="lazy"></iframe>', $src, $title)
            ."\n<p>".$line.'</p>';
    }

    /** L'adresse de l'exercice depuis la carte : elle dit d'où vient la visite. */
    public function exerciseUrl(Practice $practice): string
    {
        return $this->urls->generate('app_exercise_pratique', ['exerciseId' => $practice->exercise->id, ...self::UTM, 'utm_campaign' => $practice->exercise->id], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** « Symfony 8.2 », ou « Docker » pour un exercice qui n'est pas une nouveauté de version. */
    public function framework(Practice $practice): string
    {
        return trim($this->frameworks->labelOf($practice->framework).' '.$practice->version);
    }
}
