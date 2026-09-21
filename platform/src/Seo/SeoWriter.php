<?php

namespace App\Seo;

use App\Content\Chapter;
use App\Content\ContentRepository;
use App\Content\Framework\FrameworkRegistry;
use App\Content\EnvironmentRegistry;
use App\Content\Exercise;
use App\Content\ExerciseStory;
use App\Content\Practice;
use App\Content\Track;
use App\Instance\Branding;
use App\Payment\TrackOfferFactory;
use App\Twig\DurationExtension;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Rédige les balises de chaque type de page publique, avec les mots du site (parcours, chapitre, exercice, Pratique).
 * Les textes viennent des packs : un titre trop long perd d'abord la marque, puis ses notions, avant d'être coupé.
 *
 * Données structurées (schema.org) : Organization, WebSite, Person et FAQPage sur l'accueil, Course sur un parcours,
 * Article sur un exercice de Pratique, BreadcrumbList partout sous l'accueil.
 */
final readonly class SeoWriter
{
    public function __construct(
        private PageSeo $seo,
        private UrlGeneratorInterface $urls,
        private EnvironmentRegistry $environments,
        private TrackOfferFactory $offers,
        private Packages $packages,
        private TrackSeoText $trackText,
        private ContentRepository $content,
        private Branding $branding,
        private FrameworkRegistry $frameworks,
        private ExerciseStory $stories,
    ) {
    }

    /** @param list<array{question: string, answer: string}> $faq les questions fréquentes de la page */
    public function home(array $faq): void
    {
        $home = $this->url('app_home');
        $this->seo
            ->setTitle('Apprendre à développer en codant dans le navigateur | '.$this->branding->name(), 'Apprendre à développer en codant dans le navigateur')
            ->setDescription('Apprenez à développer en codant dans votre navigateur, sans vidéo ni installation : un vrai projet, des tests automatiques, le premier chapitre gratuit.')
            ->setCanonical($home)
            ->addStructuredData(['@graph' => [
                [
                    ...$this->organization(),
                    ...(null === ($logo = $this->branding->logoLargeUrl()) ? [] : ['logo' => $this->absolute($logo)]),
                    ...('' === $this->branding->url() ? [] : ['sameAs' => [$this->branding->url()]]),
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => $home.'#site',
                    'name' => $this->branding->name(),
                    'alternateName' => $this->branding->signature(),
                    'url' => $home,
                    'inLanguage' => 'fr-FR',
                    'publisher' => ['@id' => $home.'#organisation'],
                ],
                $this->author(),
                [
                    '@type' => 'FAQPage',
                    'mainEntity' => array_map(static fn (array $item) => [
                        '@type' => 'Question',
                        'name' => $item['question'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
                    ], $faq),
                ],
            ]]);
    }

    public function track(Track $track): void
    {
        $this->seo
            ->setFullTitle($this->trackText->title($track, $this->framework($track->environment)))
            ->setDescription($this->trackText->description($track))
            ->setCanonical($url = $this->url('app_track', ['trackId' => $track->id]));

        // Le prix affiché à un visiteur : prix courant (fondateur compris), en euros TTC.
        $offer = $this->offers->create($track, null);
        $this->seo
            ->addStructuredData([
                '@type' => 'Course',
                'name' => $track->title,
                'description' => self::plain($track->description),
                'url' => $url,
                'inLanguage' => 'fr',
                'provider' => $this->organization(),
                ...(null === ($image = $this->branding->shareUrl()) ? [] : ['image' => $this->absolute($image)]),
                'hasCourseInstance' => [
                    '@type' => 'CourseInstance',
                    'courseMode' => 'Online',
                    'inLanguage' => 'fr',
                ],
                ...(null !== ($minutes = $this->content->durationOf($track)) ? ['timeRequired' => DurationExtension::iso($minutes)] : []),
                'syllabusSections' => array_map(static fn (Chapter $chapter) => ['@type' => 'Syllabus', 'name' => $chapter->title], $track->chapters),
                'offers' => [
                    '@type' => 'Offer',
                    'category' => $offer->quote->isFree() ? 'Free' : 'Paid',
                    'price' => number_format($offer->quote->price / 100, 2, '.', ''),
                    'priceCurrency' => 'EUR',
                    'url' => $url,
                    'availability' => $offer->quote->isFree() || $offer->paymentsEnabled ? 'https://schema.org/InStock' : 'https://schema.org/PreOrder',
                ],
            ])
            ->addStructuredData($this->breadcrumb([$track->title => $url]));
    }

    public function exercise(Track $track, Chapter $chapter, Exercise $exercise): void
    {
        $framework = $this->framework($exercise->environment);
        $this->seo
            ->setTitle(
                ...$this->conceptLed($exercise->concepts, $framework, 'exercice : '.$exercise->title),
                ...[sprintf('%s – exercice %s', $exercise->title, $framework), $exercise->title],
            )
            ->setDescription(
                $this->stories->fromInstructions($exercise->instructions),
                sprintf('Un exercice du chapitre « %s », parcours %s.', $chapter->title, $track->title),
            )
            ->setCanonical($url = $this->url('app_exercise', ['trackId' => $track->id, 'exerciseId' => $exercise->id]))
            ->addStructuredData($this->breadcrumb([
                $track->title => $this->url('app_track', ['trackId' => $track->id]),
                $chapter->title => $this->url('app_chapter_summary', ['trackId' => $track->id, 'chapterId' => $chapter->id]),
                $exercise->title => $url,
            ]));
    }

    /**
     * Le sommaire d'un chapitre : ce qu'il fait apprendre, et combien d'exercices l'y amènent.
     *
     * @param list<string> $concepts les notions de ses exercices, sans doublon
     */
    public function chapter(Track $track, Chapter $chapter, int $number, array $concepts): void
    {
        $framework = $this->framework($chapter->environment ?? $track->environment);
        $count = \count($chapter->exerciseIds);
        $this->seo
            ->setTitle(
                ...$this->conceptLed($concepts, $framework, $chapter->title),
                ...[sprintf('%s – chapitre %d, parcours %s', $chapter->title, $number, $framework), $chapter->title],
            )
            ->setDescription(
                sprintf(
                    'Chapitre %d du parcours %s : %s. %d exercice%s à faire en écrivant du code, corrigé%s par des tests.',
                    $number,
                    $track->title,
                    $concepts ? mb_strtolower(implode(', ', \array_slice($concepts, 0, 4))) : mb_strtolower($chapter->title),
                    $count,
                    $count > 1 ? 's' : '',
                    $count > 1 ? 's' : '',
                ),
            )
            ->setCanonical($url = $this->url('app_chapter_summary', ['trackId' => $track->id, 'chapterId' => $chapter->id]))
            ->addStructuredData($this->breadcrumb([
                $track->title => $this->url('app_track', ['trackId' => $track->id]),
                $chapter->title => $url,
            ]));
    }

    /**
     * Les titres candidats d'une page de contenu, menés par ses notions, du plus complet au plus court
     * (PageSeo garde le premier qui tient). C'est la notion que l'on cherche dans un moteur, pas « Les prix
     * en pièces d'or » ; le libellé narratif ferme chaque candidat, car il distingue deux pages d'une même notion.
     *
     * @param list<string> $concepts
     *
     * @return list<string>
     */
    private function conceptLed(array $concepts, string $framework, string $label): array
    {
        $titles = [];
        for ($count = \count($concepts); $count > 0; --$count) {
            $titles[] = implode(', ', \array_slice($concepts, 0, $count)).' en '.$framework.' – '.$label;
        }
        if ([] !== $concepts) {
            // Dernier recours avant d'abandonner la notion : sans le framework. Jamais sans le libellé,
            // qui seul distingue deux pages — un title en double n'aide personne, et le test du sitemap le refuse.
            $titles[] = $concepts[0].' – '.$label;
        }

        return $titles;
    }

    /**
     * L'index des notions.
     *
     * @param list<array{name: string, slug: string, exercises: int}> $concepts
     */
    public function conceptList(array $concepts): void
    {
        $url = $this->url('app_concepts');
        $this->seo
            ->setTitle('Les notions travaillées en exercices | '.$this->branding->name(), 'Les notions travaillées en exercices')
            ->setDescription(sprintf(
                '%d notions de développement, et pour chacune les exercices qui la font pratiquer : on écrit le code dans le navigateur, des tests disent s\'il est juste.',
                \count($concepts),
            ))
            ->setCanonical($url)
            ->addStructuredData($this->breadcrumb(['Les notions' => $url]));
    }

    /**
     * Une notion : c'est le mot que l'on cherche dans un moteur, et la page qui rassemble ce qui le pratique.
     *
     * @param array{name: string, slug: string, exercises: list<array<string, mixed>>, practices: list<Practice>} $concept
     */
    public function concept(array $concept): void
    {
        $count = \count($concept['exercises']) + \count($concept['practices']);
        $this->seo
            ->setTitle(
                sprintf('%s : %d exercices pour la pratiquer | %s', $concept['name'], $count, $this->branding->name()),
                sprintf('%s : %d exercices pour la pratiquer', $concept['name'], $count),
                sprintf('%s en exercices', $concept['name']),
                $concept['name'],
            )
            ->setDescription(sprintf(
                'Les %d exercices qui font travailler %s : chacun pose un problème à résoudre en écrivant du code dans le navigateur, corrigé par des tests.',
                $count,
                $concept['name'],
            ))
            ->setCanonical($url = $this->url('app_concept', ['slug' => $concept['slug']]))
            ->addStructuredData($this->breadcrumb([
                'Les notions' => $this->url('app_concepts'),
                $concept['name'] => $url,
            ]));
    }

    /** @param list<Practice> $practices ceux que la liste montre sans filtre */
    public function practiceList(array $practices): void
    {
        $frameworks = array_values(array_unique(array_map(fn (Practice $p) => $this->frameworkLabel($p->framework), $practices)));
        $subject = match (\count($frameworks)) {
            0 => 'des frameworks',
            1 => $frameworks[0],
            default => implode(', ', \array_slice($frameworks, 0, -1)).' et '.end($frameworks),
        };

        $this->seo
            ->setTitle(
                sprintf('Nouveautés %s en exercices courts | %s', $subject, $this->branding->name()),
                sprintf('Nouveautés %s en exercices courts', $subject),
                'Nouveautés des frameworks en exercices courts',
            )
            ->setDescription('Des exercices courts, à part des parcours : un code écrit à l\'ancienne à réécrire avec la nouveauté du framework, directement dans le navigateur.')
            ->setCanonical($url = $this->url('app_practice'))
            ->addStructuredData($this->breadcrumb(['Pratique' => $url]));
    }

    public function practice(Practice $practice): void
    {
        $framework = trim($this->frameworkLabel($practice->framework).' '.$practice->version);
        $feature = self::lowerFirst($practice->exercise->title);

        $this->seo
            ->setTitle(
                sprintf('%s : %s – exercice', $framework, $feature),
                sprintf('%s : %s', $framework, $feature),
            )
            ->setDescription($practice->summary, sprintf('Un exercice court de la Pratique %s, à faire dans le navigateur.', $framework))
            ->setCanonical($url = $this->url('app_exercise_pratique', ['exerciseId' => $practice->exercise->id]))
            ->setType('article')
            ->addStructuredData([
                '@type' => 'Article',
                'headline' => PageSeo::shorten($practice->exercise->title, 110),
                'description' => $practice->summary,
                'url' => $url,
                'mainEntityOfPage' => $url,
                'inLanguage' => 'fr',
                ...(null === ($image = $this->branding->shareUrl()) ? [] : ['image' => $this->absolute($image)]),
                'datePublished' => $practice->published->format('Y-m-d'),
                'dateModified' => Sitemap::practiceModified($practice),
                'author' => $this->author(),
                'publisher' => [
                    ...$this->organization(),
                    ...(null === ($logo = $this->branding->logoLargeUrl()) ? [] : ['logo' => ['@type' => 'ImageObject', 'url' => $this->absolute($logo)]]),
                ],
                'keywords' => implode(', ', [$this->frameworkLabel($practice->framework), ...$practice->exercise->concepts]),
            ])
            ->addStructuredData($this->breadcrumb([
                'Pratique' => $this->url('app_practice'),
                $practice->exercise->title => $url,
            ]));
    }

    public function contact(): void
    {
        $this->seo
            ->setTitle('Contact | '.$this->branding->name())
            ->setDescription('Une question sur un parcours, un achat ou une facture, un problème sur le site : écrivez-nous, une personne lit chaque message et vous répond par email.')
            ->setCanonical($this->url('app_contact'));
    }

    public function organizations(): void
    {
        $url = $this->url('app_organizations');
        $this->seo
            ->setTitle('Former une classe ou une équipe au développement | '.$this->branding->name(), 'Former une classe ou une équipe au développement')
            ->setDescription('Écoles, organismes de formation, entreprises : vos apprenants codent dans le navigateur, sans rien installer, et vous suivez leur progression exercice par exercice. Sur devis.')
            ->setCanonical($url)
            ->addStructuredData($this->breadcrumb(['Écoles et entreprises' => $url]));
    }

    public function selfHosting(): void
    {
        $url = $this->url('app_self_hosting');
        $this->seo
            ->setTitle('Auto-héberger la plateforme, moteur open source | '.$this->branding->name(), 'Auto-héberger la plateforme, moteur open source')
            ->setDescription(sprintf('Le moteur de %s est libre (AGPL-3.0) : installez la plateforme sur votre serveur avec Docker, écrivez vos parcours, ou utilisez les siens sur devis.', $this->branding->name()))
            ->setCanonical($url)
            ->addStructuredData($this->breadcrumb(['Auto-hébergement' => $url]));
    }

    public function legalNotice(): void
    {
        $this->seo
            ->setTitle('Mentions légales | '.$this->branding->name())
            ->setDescription('Mentions légales du site : éditeur, directeur de la publication, hébergeur de la plateforme et du bac à sable où s\'exécute le code des apprenants.')
            ->setCanonical($this->url('app_legal_notice'));
    }

    public function terms(): void
    {
        $this->seo
            ->setTitle('Conditions générales de vente | '.$this->branding->name())
            ->setDescription('Conditions générales de vente des parcours : prix TTC, commande et paiement, accès aux contenus, droit de rétractation, garanties et médiation.')
            ->setCanonical($this->url('app_terms'));
    }

    public function privacy(): void
    {
        $this->seo
            ->setTitle('Politique de confidentialité | '.$this->branding->name())
            ->setDescription('Politique de confidentialité : données collectées, finalités, durées de conservation, sous-traitants et exercice de vos droits sur vos données.')
            ->setCanonical($this->url('app_privacy'));
    }

    /** « Symfony », « Laravel »… d'après l'environnement d'exécution. */
    public function framework(string $environmentId): string
    {
        return $this->environments->has($environmentId)
            ? $this->environments->get($environmentId)->framework->label
            : $this->frameworks->default()->label;
    }

    /** @return array<string, mixed> l'éditeur du site */
    private function organization(): array
    {
        return ['@type' => 'Organization', '@id' => $this->url('app_home').'#organisation', 'name' => $this->branding->name(), 'url' => $this->url('app_home')];
    }

    /**
     * L'auteur des parcours et des exercices. La personne derrière Forelse ne vaut que pour Forelse :
     * une autre instance publie son organisation.
     *
     * @return array<string, mixed>
     */
    private function author(): array
    {
        if (!$this->branding->isDefault()) {
            return ['@id' => $this->url('app_home').'#organisation'];
        }

        return [
            '@type' => 'Person',
            '@id' => $this->url('app_home').'#auteur',
            'name' => 'Arnaud Pointet',
            'jobTitle' => 'Développeur indépendant',
            'worksFor' => ['@id' => $this->url('app_home').'#organisation'],
            'url' => $this->branding->url(),
        ];
    }

    /** Une URL du site (chemin absolu) vue de l'extérieur, pour les données structurées. */
    private function absolute(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : rtrim($this->url('app_home'), '/').$path;
    }

    /**
     * @param array<string, string> $trail nom => adresse, après l'accueil
     *
     * @return array<string, mixed>
     */
    private function breadcrumb(array $trail): array
    {
        $items = [];
        foreach (['Accueil' => $this->url('app_home'), ...$trail] as $name => $url) {
            $items[] = ['@type' => 'ListItem', 'position' => \count($items) + 1, 'name' => (string) $name, 'item' => $url];
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    private function asset(string $path): string
    {
        return rtrim($this->url('app_home'), '/').$this->packages->getUrl($path);
    }

    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function frameworkLabel(string $framework): string
    {
        return $this->frameworks->has($framework) ? $this->frameworks->get($framework)->label : ucfirst($framework);
    }

    /** « Lire un en-tête » → « lire un en-tête », mais « PHP 8.4 » reste tel quel. */
    private static function lowerFirst(string $text): string
    {
        return 1 === preg_match('/^\p{Lu}\p{Ll}/u', $text) ? mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1) : $text;
    }

    /** @param array<string, string> $parameters */
    private function url(string $route, array $parameters = []): string
    {
        return $this->urls->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
