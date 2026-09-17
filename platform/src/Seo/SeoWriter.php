<?php

namespace App\Seo;

use App\Content\Chapter;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\Exercise;
use App\Content\LessonRenderer;
use App\Content\Practice;
use App\Content\Track;
use App\Controller\PracticeController;
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
        private LessonRenderer $markdown,
        private TrackOfferFactory $offers,
        private Packages $packages,
        private TrackSeoText $trackText,
        private ContentRepository $content,
    ) {
    }

    /** @param list<array{question: string, answer: string}> $faq les questions fréquentes de la page */
    public function home(array $faq): void
    {
        $home = $this->url('app_home');
        $this->seo
            ->setTitle('Apprendre à développer en codant dans le navigateur | '.PageSeo::SITE_NAME, 'Apprendre à développer en codant dans le navigateur')
            ->setDescription('Apprenez à développer en codant dans votre navigateur, sans vidéo ni installation : un vrai projet, des tests automatiques, le premier chapitre gratuit.')
            ->setCanonical($home)
            ->addStructuredData(['@graph' => [
                [...$this->organization(), 'logo' => $this->asset('img/logo.png'), 'sameAs' => ['https://forelse.fr']],
                [
                    '@type' => 'WebSite',
                    '@id' => $home.'#site',
                    'name' => PageSeo::SITE_NAME,
                    'alternateName' => 'Forelse · apprendre',
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
                'image' => $this->asset('img/og-forelse.png'),
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
        $prefix = sprintf('%s – exercice %s', $exercise->title, $this->framework($exercise->environment));
        $titles = [];
        for ($count = \count($exercise->concepts); $count > 0; --$count) {
            $titles[] = $prefix.' : '.implode(', ', \array_slice($exercise->concepts, 0, $count));
        }

        $this->seo
            ->setTitle(...[...$titles, $prefix, $exercise->title])
            ->setDescription(
                $this->story($exercise->instructions),
                sprintf('Un exercice du chapitre « %s », parcours %s.', $chapter->title, $track->title),
            )
            ->setCanonical($url = $this->url('app_exercise', ['trackId' => $track->id, 'exerciseId' => $exercise->id]))
            ->addStructuredData($this->breadcrumb([
                $track->title => $this->url('app_track', ['trackId' => $track->id]),
                $exercise->title => $url,
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
                sprintf('Nouveautés %s en exercices courts | %s', $subject, PageSeo::SITE_NAME),
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
                'image' => $this->asset('img/og-forelse.png'),
                'datePublished' => $practice->published->format('Y-m-d'),
                'dateModified' => Sitemap::practiceModified($practice),
                'author' => $this->author(),
                'publisher' => [...$this->organization(), 'logo' => ['@type' => 'ImageObject', 'url' => $this->asset('img/logo.png')]],
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
            ->setTitle('Contact | '.PageSeo::SITE_NAME)
            ->setDescription('Une question sur un parcours, un achat ou une facture, un problème sur le site : écrivez-nous, une personne lit chaque message et vous répond par email.')
            ->setCanonical($this->url('app_contact'));
    }

    public function organizations(): void
    {
        $url = $this->url('app_organizations');
        $this->seo
            ->setTitle('Former une classe ou une équipe au développement | '.PageSeo::SITE_NAME, 'Former une classe ou une équipe au développement')
            ->setDescription('Écoles, organismes de formation, entreprises : vos apprenants codent dans le navigateur, sans rien installer, et vous suivez leur progression exercice par exercice. Sur devis.')
            ->setCanonical($url)
            ->addStructuredData($this->breadcrumb(['Écoles et entreprises' => $url]));
    }

    public function selfHosting(): void
    {
        $url = $this->url('app_self_hosting');
        $this->seo
            ->setTitle('Auto-héberger la plateforme, moteur open source | '.PageSeo::SITE_NAME, 'Auto-héberger la plateforme, moteur open source')
            ->setDescription('Le moteur de Forelse est libre (AGPL-3.0) : installez la plateforme sur votre serveur avec Docker, écrivez vos parcours, ou utilisez ceux de Forelse sur devis.')
            ->setCanonical($url)
            ->addStructuredData($this->breadcrumb(['Auto-hébergement' => $url]));
    }

    public function legalNotice(): void
    {
        $this->seo
            ->setTitle('Mentions légales | '.PageSeo::SITE_NAME)
            ->setDescription('Mentions légales du site : éditeur, directeur de la publication, hébergeur de la plateforme et du bac à sable où s\'exécute le code des apprenants.')
            ->setCanonical($this->url('app_legal_notice'));
    }

    public function terms(): void
    {
        $this->seo
            ->setTitle('Conditions générales de vente | '.PageSeo::SITE_NAME)
            ->setDescription('Conditions générales de vente des parcours : prix TTC, commande et paiement, accès aux contenus, droit de rétractation, garanties et médiation.')
            ->setCanonical($this->url('app_terms'));
    }

    public function privacy(): void
    {
        $this->seo
            ->setTitle('Politique de confidentialité | '.PageSeo::SITE_NAME)
            ->setDescription('Politique de confidentialité : données collectées, finalités, durées de conservation, sous-traitants et exercice de vos droits sur vos données.')
            ->setCanonical($this->url('app_privacy'));
    }

    /** « Symfony », « Laravel »… d'après l'environnement d'exécution. */
    public function framework(string $environmentId): string
    {
        return $this->frameworkLabel($this->environments->has($environmentId) ? $this->environments->get($environmentId)->framework : 'symfony');
    }

    /** Le premier paragraphe de la consigne, en texte brut : le besoin posé par le personnage. */
    public function story(string $instructions): string
    {
        $text = trim(html_entity_decode(strip_tags((string) preg_replace('#</(p|h[1-6]|li|ul|ol|pre|table|blockquote)>#', "\$0\n\n", $this->markdown->toHtml($instructions))), \ENT_QUOTES | \ENT_HTML5));
        foreach (preg_split('/\n\s*\n/', $text) ?: [] as $paragraph) {
            // Un titre répète celui de l'exercice : on cherche une vraie phrase.
            if (mb_strlen(trim($paragraph)) >= 40) {
                return trim($paragraph);
            }
        }

        return $text;
    }

    /** @return array<string, mixed> l'éditeur du site */
    private function organization(): array
    {
        return ['@type' => 'Organization', '@id' => $this->url('app_home').'#organisation', 'name' => PageSeo::SITE_NAME, 'url' => $this->url('app_home')];
    }

    /** @return array<string, mixed> l'auteur des parcours et des exercices */
    private function author(): array
    {
        return [
            '@type' => 'Person',
            '@id' => $this->url('app_home').'#auteur',
            'name' => 'Arnaud Pointet',
            'jobTitle' => 'Développeur indépendant',
            'worksFor' => ['@id' => $this->url('app_home').'#organisation'],
            'url' => 'https://forelse.fr',
        ];
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
        return PracticeController::FRAMEWORKS[$framework] ?? ucfirst($framework);
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
