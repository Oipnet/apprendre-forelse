<?php

namespace App\Controller;

use App\Content\Chapter;
use App\Content\ContentRepository;
use App\Content\TrackVisibility;
use App\Content\Track;
use App\Entity\User;
use App\Payment\TrackOfferFactory;
use App\Seo\SeoWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Page d'accueil : la promesse, la démonstration, puis les parcours installés (packs de contenu). */
final class HomeController extends AbstractController
{
    public function __construct(
        /** Bêta fermée : la page propose la liste d'attente plutôt que l'inscription. */
        #[Autowire(env: 'bool:REGISTRATION_INVITE_ONLY')]
        private readonly bool $inviteOnly,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(ContentRepository $content, TrackVisibility $visibility, TrackOfferFactory $offers, SeoWriter $seo): Response
    {
        $user = $this->getUser();
        $tracks = [];
        foreach ($visibility->tracks() as $track) {
            $firstId = $track->exerciseIds()[0] ?? null;
            $first = null === $firstId ? null : $content->findExercise($track->id, $firstId);
            $tracks[] = [
                'track' => $track,
                'pack' => $content->packs()[$track->packId],
                'exercises' => \count($track->exerciseIds()),
                'chapters' => array_map(fn (Chapter $chapter) => [
                    'chapter' => $chapter,
                    'exercises' => \count($chapter->exerciseIds),
                    'concepts' => $this->concepts($content, $track, $chapter),
                ], $track->chapters),
                // Porte d'entrée du parcours : son premier chapitre est gratuit pour tout compte (sauf parcours en préparation).
                'tryUrl' => $first && !$track->isRestricted() ? $this->generateUrl('app_exercise', ['trackId' => $track->id, 'exerciseId' => $first->id]) : null,
                'offer' => $offers->create($track, $user instanceof User ? $user : null),
            ];
        }

        // Le bouton principal mène au premier exercice du premier parcours ; à défaut, à l'inscription.
        $try = array_values(array_filter(array_column($tracks, 'tryUrl')))[0] ?? null;

        // Les parcours en préparation s'annoncent (« bientôt »), sans lien : leur page n'existe pas pour le visiteur.
        $upcoming = array_values(array_filter($content->tracks(), static fn (Track $track) => $track->isRestricted() && !$visibility->isVisible($track)));
        usort($upcoming, static fn (Track $a, Track $b) => [$a->order ?? \PHP_INT_MAX, $a->title] <=> [$b->order ?? \PHP_INT_MAX, $b->title]);

        $faq = self::faq(array_map(static fn (array $item) => $item['track'], $tracks), $upcoming);
        $seo->home($faq);

        return $this->render('home.html.twig', [
            'tracks' => $tracks,
            'upcoming' => $upcoming,
            'faq' => $faq,
            'tryUrl' => $try ?? $this->generateUrl('app_register'),
            'inviteOnly' => $this->inviteOnly,
        ]);
    }

    /**
     * Les questions fréquentes, affichées par l'accueil et reprises telles quelles dans son JSON-LD (FAQPage).
     *
     * @param list<Track> $published parcours ouverts au visiteur
     * @param list<Track> $upcoming  parcours en préparation
     *
     * @return list<array{question: string, answer: string}>
     */
    private static function faq(array $published, array $upcoming): array
    {
        $list = static function (array $tracks): string {
            $names = array_map(static fn (Track $track) => $track->title, $tracks);
            $last = array_pop($names);

            return $names ? implode(', ', $names).' et '.$last : (string) $last;
        };
        $tracks = ($published ? 'Aujourd\'hui : '.$list($published).'.' : 'Aucun parcours n\'est encore ouvert.')
            .($upcoming ? ' En préparation : '.$list($upcoming).'.' : '');

        return [
            ['question' => 'Ça tourne vraiment dans le navigateur ?', 'answer' => 'Oui. Le langage et le framework du parcours s\'exécutent en WebAssembly, dans un onglet. Rien ne part sur un serveur pour exécuter votre code.'],
            ['question' => 'Quels parcours ?', 'answer' => $tracks],
            ['question' => 'Combien ça coûte ?', 'answer' => 'Le premier chapitre de chaque parcours est gratuit : il suffit de créer un compte. Le parcours complet s\'achète une fois, prix TTC affiché, pour un accès à vie et à ses mises à jour : pas d\'abonnement. Une école ou une entreprise peut aussi ouvrir l\'accès à tout un groupe, sur devis.'],
            ['question' => 'Je bloque sur un exercice, que se passe-t-il ?', 'answer' => 'Vous demandez un indice, puis un deuxième, plus précis. Avec un compte, la solution complète est consultable, mais elle ne rapporte pas l\'XP de l\'exercice.'],
            ['question' => 'Quelle différence avec les tutoriels vidéo ?', 'answer' => 'Vous ne regardez rien : vous écrivez tout le code vous-même, sur un projet qui grandit exercice après exercice. Le temps passé est du temps de pratique.'],
            ['question' => 'Est-ce à jour ?', 'answer' => 'Chaque parcours cible les versions actuelles (Symfony 8 et PHP 8.4 pour le premier), et les exercices sont vérifiés automatiquement à chaque mise à jour du moteur.'],
            ['question' => 'Puis-je l\'héberger moi-même ?', 'answer' => 'Oui, à terme : le moteur est sous licence AGPL et son code sera publié à l\'ouverture. Les parcours, eux, sont le contenu payant : c\'est ce que vous achetez.'],
        ];
    }

    /**
     * Les notions abordées dans le chapitre, dans l'ordre où elles apparaissent (au plus cinq, sans doublon).
     *
     * @return list<string>
     */
    private function concepts(ContentRepository $content, Track $track, Chapter $chapter): array
    {
        $concepts = [];
        foreach ($chapter->exerciseIds as $exerciseId) {
            foreach ($content->findExercise($track->id, $exerciseId)?->concepts ?? [] as $concept) {
                $concepts[$concept] = true;
            }
        }

        return \array_slice(array_keys($concepts), 0, 5);
    }
}
