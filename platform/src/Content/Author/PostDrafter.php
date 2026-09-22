<?php

namespace App\Content\Author;

use App\Ai\ModelClient;
use App\Content\Chapter;
use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\Exercise;
use App\Content\Framework\FrameworkProfile;
use App\Content\Practice;
use App\Content\Track;
use App\Instance\Branding;

/**
 * Trois brouillons de post LinkedIn pour annoncer un parcours ou un exercice de Pratique, à partir de ce
 * que le pack contient déjà. L'auteur choisit, relit, copie : rien n'est publié d'ici, et rien n'est
 * enregistré — un post est un texte qu'on emporte, pas un objet du contenu.
 *
 * Facultatif comme le reste de l'assistance : sans clé d'API, le bouton n'apparaît pas.
 */
final class PostDrafter
{
    /** Les trois angles proposés à chaque génération : l'auteur en garde un. */
    public const array ANGLES = [
        'annonce' => 'Annonce',
        'coulisses' => 'Coulisses',
        'pedagogique' => 'Pédagogique',
    ];

    /** LinkedIn coupe le post à 3 000 caractères ; au-delà de 1 600 il est déjà long. */
    public const int MAX_CARACTERES = 3000;
    /** Et la première ligne à ~200 caractères, derrière « …voir plus » : c'est elle qui décide de la lecture. */
    public const int MAX_ACCROCHE = 200;
    /** Au-delà, les consignes d'un exercice sont tronquées : le modèle n'a pas besoin de tout. */
    private const int MAX_CONSIGNES_CHARS = 6000;

    /** L'outil impose la forme de la réponse : trois variantes, chacune avec son angle. */
    private const array OUTIL = [
        'name' => 'ecrire_posts',
        'description' => 'Écrit les trois brouillons de post LinkedIn, un par angle.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'posts' => [
                    'type' => 'array',
                    'description' => 'Exactement trois posts, un par angle, dans l\'ordre : annonce, coulisses, pedagogique.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'angle' => ['type' => 'string', 'enum' => ['annonce', 'coulisses', 'pedagogique']],
                            'texte' => ['type' => 'string', 'description' => 'Le post entier, tel qu\'il sera collé dans LinkedIn (texte brut, retours à la ligne compris).'],
                        ],
                        'required' => ['angle', 'texte'],
                    ],
                ],
            ],
            'required' => ['posts'],
        ],
    ];

    public function __construct(
        private readonly ModelClient $modele,
        private readonly ContentRepository $content,
        private readonly EnvironmentRegistry $environments,
        private readonly Branding $branding,
    ) {
    }

    public function disponible(): bool
    {
        return $this->modele->disponible();
    }

    /**
     * Le parcours : ce qu'on y apprend, pour qui, et où il en est.
     *
     * @return list<array{angle: string, label: string, texte: string, caracteres: int}>
     */
    public function pourParcours(Track $track, string $url, string $precision = ''): array
    {
        // Tous les exercices : ce sont eux qui donnent le compte, les XP et les notions annoncés dans le post.
        $exercices = array_values(array_filter(array_map(
            fn (string $id) => $this->content->findExercise($track->id, $id),
            $track->exerciseIds(),
        )));
        $framework = $this->environments->get($track->environment)->framework;

        $chapitres = array_map(
            static fn (Chapter $chapitre) => sprintf('- %s (%d exercice%s)', $chapitre->title, $nombre = \count($chapitre->exerciseIds), $nombre > 1 ? 's' : ''),
            $track->chapters,
        );

        $contexte = implode("\n\n", array_filter([
            sprintf("Objet du post : le parcours « %s ».\nAdresse publique : %s", $track->title, $url),
            'Résumé du parcours : '.trim($track->description),
            sprintf('Technologie : %s. %d chapitres, %d exercices, %d XP.', $framework->label, \count($track->chapters), \count($exercices), array_sum(array_map(static fn (Exercise $e) => $e->xp, $exercices))),
            $chapitres ? "Les chapitres :\n".implode("\n", $chapitres) : null,
            ($notions = self::notions($exercices)) ? 'Notions travaillées : '.$notions : null,
            $track->isRestricted() ? 'Ce parcours est encore en préparation : le post annonce ce qui arrive, il ne promet pas un accès immédiat.' : null,
        ]));

        return $this->ecrire($framework, $contexte, $url, $precision);
    }

    /**
     * Un exercice de Pratique : une page publique, courte et datée — le format qui se partage le mieux.
     *
     * @return list<array{angle: string, label: string, texte: string, caracteres: int}>
     */
    public function pourPratique(Practice $practice, string $url, string $precision = ''): array
    {
        $exercise = $practice->exercise;
        $framework = $this->environments->get($exercise->environment)->framework;

        $contexte = implode("\n\n", array_filter([
            sprintf("Objet du post : l'exercice de Pratique « %s ».\nAdresse publique : %s", $exercise->title, $url),
            'Résumé : '.trim($practice->summary),
            sprintf('Technologie : %s%s.', $framework->label, null === $practice->version ? '' : sprintf(', nouveauté de la version %s', $practice->version)),
            ($notions = self::notions([$exercise])) ? 'Notions travaillées : '.$notions : null,
            'Consignes données à l\'apprenant :'."\n".mb_substr(trim($exercise->instructions), 0, self::MAX_CONSIGNES_CHARS),
            $practice->pullRequest ? 'Pull request d\'origine : '.$practice->pullRequest : null,
            $practice->isRestricted() || $practice->isScheduled() ? 'Cet exercice n\'est pas encore visible du public : le post est à garder pour le jour de sa parution.' : null,
        ]));

        return $this->ecrire($framework, $contexte, $url, $precision);
    }

    /**
     * @return list<array{angle: string, label: string, texte: string, caracteres: int}>
     */
    private function ecrire(FrameworkProfile $framework, string $contexte, string $url, string $precision): array
    {
        $message = '' === trim($precision)
            ? $contexte
            : $contexte."\n\n".'Demande de l\'auteur, à suivre en priorité : '.trim($precision);

        $entree = $this->modele->appeler($this->consignes($framework, $url), [['role' => 'user', 'content' => $message]], self::OUTIL);

        $posts = [];
        foreach (\is_array($entree['posts'] ?? null) ? $entree['posts'] : [] as $post) {
            $angle = \is_array($post) ? ($post['angle'] ?? null) : null;
            $texte = \is_array($post) ? ($post['texte'] ?? null) : null;
            if (!\is_string($angle) || !isset(self::ANGLES[$angle]) || !\is_string($texte) || '' === trim($texte)) {
                continue;
            }
            $texte = self::avecLeLien(trim($texte), $url);
            $posts[] = [
                'angle' => $angle,
                'label' => self::ANGLES[$angle],
                'texte' => $texte,
                'caracteres' => mb_strlen($texte),
            ];
        }

        if (!$posts) {
            throw new ContentException('Le modèle n\'a renvoyé aucun post exploitable.');
        }

        return $posts;
    }

    /** Un post sans son lien ne sert à rien : on l'ajoute plutôt que de renvoyer l'auteur au modèle. */
    private static function avecLeLien(string $texte, string $url): string
    {
        return str_contains($texte, $url) ? $texte : $texte."\n\n".$url;
    }

    /**
     * @param list<Exercise> $exercices
     */
    private static function notions(array $exercices): string
    {
        $concepts = [];
        foreach ($exercices as $exercice) {
            foreach ($exercice->concepts as $concept) {
                $concepts[$concept] = true;
            }
        }

        return implode(', ', array_keys($concepts));
    }

    private function consignes(FrameworkProfile $framework, string $url): string
    {
        return str_replace(
            ['{framework}', '{marque}', '{tagline}', '{url}', '{max}', '{accroche}'],
            [$framework->label, $this->branding->name(), $this->branding->tagline(), $url, number_format(self::MAX_CARACTERES, 0, ',', ' '), (string) self::MAX_ACCROCHE],
            <<<'TEXTE'
                Tu écris les brouillons de post LinkedIn de l'auteur de {marque}, une plateforme dont la
                promesse est « {tagline} » : on y apprend en codant dans le navigateur, sur un vrai projet
                {framework} validé par des tests automatiques. L'auteur publie sous son propre nom, à la
                première personne du singulier. Tu réponds uniquement en appelant l'outil ecrire_posts.

                Tu écris trois posts du même contenu, sous trois angles :
                - annonce : ce qui est disponible, pour qui, et ce qu'on en retire. Le post le plus direct.
                - coulisses : une décision prise en construisant ce contenu, et pourquoi. Le point de vue de
                  celui qui fabrique, pas celui qui vend.
                - pedagogique : une notion précise tirée du contenu, expliquée pour de bon. Le post doit rester
                  utile à quelqu'un qui ne cliquera jamais sur le lien.

                Ce qu'est un bon post ici :
                - La première ligne est lue seule : LinkedIn coupe le reste derrière « …voir plus ». Moins de
                  {accroche} caractères, et elle doit donner envie sans promettre n'importe quoi. Jamais « Je suis
                  ravi de vous annoncer ».
                - Entre 700 et 1 600 caractères, {max} au grand maximum. Des paragraphes de deux ou trois
                  lignes, séparés par une ligne vide.
                - Le lien {url} sur sa propre ligne, vers la fin, suivi de trois à cinq hashtags (#Symfony,
                  #PHP, #Formation…).
                - Ton sobre et direct, vouvoiement s'il s'adresse au lecteur. Pas d'emoji, pas de flèches
                  décoratives, pas de liste à puces déguisée en emoji, pas d'appel à commenter fabriqué.

                Règles de forme (LinkedIn les impose) :
                - Texte brut : LinkedIn ne rend ni markdown ni HTML. Pas de **gras**, pas de titre en #, pas de
                  [lien](url). Un tiret « - » en début de ligne reste lisible, c'est la seule liste permise.
                - Un extrait de code tient en deux ou trois lignes, recopié tel quel, sans bloc ``` : LinkedIn
                  l'afficherait avec les accents graves.
                - Tout en français.
                - N'invente rien : chiffres, notions et contenu viennent du contexte fourni, et de rien d'autre.
                TEXTE
        );
    }
}
