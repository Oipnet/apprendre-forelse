<?php

namespace App\Scratch;

use App\Content\Track;
use App\Content\TrackVisibility;
use App\Entity\User;
use App\Payment\TrackOfferFactory;
use App\Twig\MoneyExtension;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * L'encart d'un parcours sous l'aperçu du bac à sable (playground/src/app/scratch-offer.ts) : le bac à sable est la
 * première porte d'entrée du site, et rien n'y menait vers un parcours payant.
 *
 * Le parcours se désigne dans son track.yaml (`scratch: [php]`). Il n'est proposé que s'il est public, traduit dans la
 * langue de la page, et en vente pour ce visiteur : ni à celui qui l'a déjà, ni quand le paiement n'est pas configuré.
 * Le prix affiché est celui que ce visiteur paierait (prix fondateur compris), jamais un texte figé.
 */
final readonly class ScratchOfferFactory
{
    public function __construct(
        private TrackVisibility $tracks,
        private TrackOfferFactory $offers,
        private Security $security,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private MoneyExtension $money,
    ) {
    }

    /** @return array{track: string, title: string, text: string, price: string, cta: string, url: string, dismiss: string}|null */
    public function create(ScratchFramework $framework): ?array
    {
        $track = $this->promoted($framework);
        if (null === $track) {
            return null;
        }
        $user = $this->security->getUser();
        $offer = $this->offers->create($track, $user instanceof User ? $user : null);
        if (!$offer->isPurchasable()) {
            return null;
        }

        return [
            'track' => $track->id,
            'title' => $this->translator->trans('bac_a_sable.offre.titre', ['framework' => $framework->label()], 'apprentissage'),
            'text' => $this->translator->trans('bac_a_sable.offre.texte', ['parcours' => $track->title], 'apprentissage'),
            'price' => $this->translator->trans('bac_a_sable.offre.prix', ['prix' => $this->money->euros($offer->quote->price)], 'apprentissage'),
            'cta' => $this->translator->trans('bac_a_sable.offre.voir', [], 'apprentissage'),
            'url' => $this->urls->generate('app_track', ['trackId' => $track->id]),
            'dismiss' => $this->translator->trans('bac_a_sable.offre.fermer', [], 'apprentissage'),
        ];
    }

    /** Le premier parcours public (dans l'ordre d'affichage) qui se propose dans ce bac à sable, dans la langue de la page. */
    private function promoted(ScratchFramework $framework): ?Track
    {
        $candidates = array_filter(
            $this->tracks->tracks(),
            static fn (Track $track) => !$track->isRestricted() && \in_array($framework->value, $track->scratch, true),
        );
        usort($candidates, static fn (Track $a, Track $b) => [null === $a->order, $a->order, $a->id] <=> [null === $b->order, $b->order, $b->id]);
        foreach ($candidates as $track) {
            if (null !== $here = $this->tracks->findHere($track->id)) {
                return $here;
            }
        }

        return null;
    }
}
