<?php

namespace App\Content;

use App\Content\Framework\FrameworkProfile;
use App\Content\Framework\FrameworkRegistry;
use App\Entity\User;
use App\Payment\TrackOfferFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Ce que l'accueil montre des parcours : ceux qui sont ouverts, ceux en préparation, et les questions fréquentes. */
final readonly class HomePage
{
    /** Notions annoncées par chapitre, au plus. */
    private const int CONCEPTS_PER_CHAPTER = 5;

    public function __construct(
        private ContentRepository $content,
        private TrackVisibility $visibility,
        private PracticeVisibility $practices,
        private EnvironmentRegistry $environments,
        private FrameworkRegistry $frameworks,
        private TrackOfferFactory $offers,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        /** Instance sur invitation : la Pratique demande un compte, le visiteur n'a rien à essayer. */
        #[Autowire(env: 'bool:REGISTRATION_INVITE_ONLY')]
        private bool $inviteOnly = false,
    ) {
    }

    /**
     * @return array{
     *     tracks: list<array<string, mixed>>,
     *     upcoming: list<Track>,
     *     faq: list<array{question: string, answer: string}>,
     *     tryUrl: string,
     *     tryPractice: bool,
     * }
     */
    public function of(?User $user): array
    {
        $published = array_values($this->visibility->tracksHere());
        $tracks = array_map(fn (Track $track) => $this->card($track, $user), $published);
        $upcoming = $this->upcoming();
        // Le bouton principal : pour un visiteur, un exercice de Pratique, qui se joue sans compte (le premier chapitre
        // d'un parcours en demande un) ; pour un compte, le premier exercice du premier parcours ; à défaut, l'inscription.
        $practiceUrl = null === $user ? $this->practiceUrl() : null;
        $tryUrl = $practiceUrl ?? array_first(array_filter(array_column($tracks, 'tryUrl'))) ?? $this->urls->generate('app_register');

        return [
            'tracks' => $tracks,
            'upcoming' => $upcoming,
            'faq' => $this->faq($published, $upcoming),
            'tryUrl' => $tryUrl,
            'tryPractice' => null !== $practiceUrl,
        ];
    }

    /**
     * Le catalogue (/parcours) : les fiches des parcours publiés, filtrées par framework si on le demande, et les
     * frameworks qu'on peut choisir (seulement ceux des parcours publiés).
     *
     * @return array{tracks: list<array<string, mixed>>, upcoming: list<Track>, frameworks: array<string, string>, framework: string|null}
     */
    public function catalogue(?User $user, ?string $framework): array
    {
        $published = array_values($this->visibility->tracksHere());
        $frameworks = [];
        foreach ($published as $track) {
            $profile = $this->frameworkOf($track);
            $frameworks[$profile->id] ??= $profile->label;
        }
        // Un framework inconnu (lien ancien, faute de frappe) montre tout, plutôt qu'une page vide.
        $framework = null !== $framework && isset($frameworks[$framework]) ? $framework : null;
        $shown = null === $framework ? $published : array_values(array_filter($published, fn (Track $track) => $this->frameworkOf($track)->id === $framework));

        return [
            'tracks' => array_map(fn (Track $track) => $this->card($track, $user), $shown),
            'upcoming' => null === $framework ? $this->upcoming() : [],
            'frameworks' => $frameworks,
            'framework' => $framework,
        ];
    }

    private function frameworkOf(Track $track): FrameworkProfile
    {
        return $this->environments->has($track->environment) ? $this->environments->get($track->environment)->framework : $this->frameworks->default();
    }

    /** Le plus récent des exercices de Pratique publiés, sauf sur une instance sur invitation. */
    private function practiceUrl(): ?string
    {
        $practice = $this->inviteOnly ? null : array_values($this->practices->practices())[0] ?? null;

        return null === $practice ? null : $this->urls->generate('app_exercise_pratique', ['exerciseId' => $practice->exercise->id]);
    }

    /** @return array<string, mixed> */
    private function card(Track $track, ?User $user): array
    {
        $firstId = $track->exerciseIds()[0] ?? null;
        $first = null === $firstId ? null : $this->content->findExercise($track->id, $firstId, $track->locale);

        return [
            'track' => $track,
            'pack' => $this->content->packs()[$track->packId],
            'exercises' => \count($track->exerciseIds()),
            'chapters' => array_map(fn (Chapter $chapter) => [
                'chapter' => $chapter,
                'exercises' => \count($chapter->exerciseIds),
                'concepts' => $this->concepts($track, $chapter),
            ], $track->chapters),
            // Porte d'entrée du parcours : son premier chapitre est gratuit pour tout compte (sauf parcours en préparation).
            'tryUrl' => $first && !$track->isRestricted() ? $this->urls->generate('app_exercise', ['_locale' => $track->locale, 'trackId' => $track->id, 'exerciseId' => $first->id]) : null,
            'offer' => $this->offers->create($track, $user),
            // Sur une page dans une autre langue, la fiche dit que le parcours, lui, n'y est pas traduit.
            'language' => $this->visibility->locale() === $track->locale ? null : $track->locale,
        ];
    }

    /**
     * Les parcours en préparation s'annoncent (« bientôt »), sans lien : leur page n'existe pas pour le visiteur.
     *
     * @return list<Track>
     */
    private function upcoming(): array
    {
        $upcoming = array_values(array_filter($this->content->tracks(), fn (Track $track) => $track->isRestricted() && !$this->visibility->isVisible($track)));
        usort($upcoming, static fn (Track $a, Track $b) => [$a->order ?? \PHP_INT_MAX, $a->title] <=> [$b->order ?? \PHP_INT_MAX, $b->title]);

        return $upcoming;
    }

    /**
     * Les notions abordées dans le chapitre, dans l'ordre où elles apparaissent (sans doublon).
     *
     * @return list<string>
     */
    private function concepts(Track $track, Chapter $chapter): array
    {
        $concepts = [];
        foreach ($chapter->exerciseIds as $exerciseId) {
            foreach ($this->content->findExercise($track->id, $exerciseId, $track->locale)->concepts ?? [] as $concept) {
                $concepts[$concept] = true;
            }
        }

        return \array_slice(array_keys($concepts), 0, self::CONCEPTS_PER_CHAPTER);
    }

    /**
     * Les questions fréquentes, affichées par l'accueil et reprises telles quelles dans son JSON-LD (FAQPage).
     *
     * @param list<Track> $published parcours ouverts au visiteur
     * @param list<Track> $upcoming  parcours en préparation
     *
     * @return list<array{question: string, answer: string}>
     */
    private function faq(array $published, array $upcoming): array
    {
        $list = function (array $tracks): string {
            $names = array_map(static fn (Track $track) => $track->title, $tracks);
            $last = array_pop($names);

            return $names ? $this->trans('faq.parcours.liste', ['debut' => implode(', ', $names), 'dernier' => (string) $last]) : (string) $last;
        };
        $tracks = ($published ? $this->trans('faq.parcours.ouverts', ['liste' => $list($published)]) : $this->trans('faq.parcours.aucun'))
            .($upcoming ? ' '.$this->trans('faq.parcours.en_preparation', ['liste' => $list($upcoming)]) : '');

        return [
            $this->faqItem('navigateur'),
            $this->faqItem('parcours', $tracks),
            $this->faqItem('prix'),
            $this->faqItem('blocage'),
            $this->faqItem('video'),
            $this->faqItem('a_jour'),
            $this->faqItem('hebergement'),
        ];
    }

    /** @return array{question: string, answer: string} la question faq.<clé>, et sa réponse (faq.<clé>.reponse, à défaut de $answer) */
    private function faqItem(string $key, ?string $answer = null): array
    {
        return ['question' => $this->trans('faq.'.$key.'.question'), 'answer' => $answer ?? $this->trans('faq.'.$key.'.reponse')];
    }

    /** @param array<string, string> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'accueil');
    }
}
