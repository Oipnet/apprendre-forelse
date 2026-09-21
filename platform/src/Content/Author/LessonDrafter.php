<?php

namespace App\Content\Author;

use App\Ai\ModelClient;
use App\Content\Chapter;
use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\Framework\FrameworkProfile;
use App\Content\EnvironmentRegistry;
use App\Content\Exercise;
use App\Content\Track;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Point de départ d'une fiche de cours (chapters/<chapitre>/lesson.md), pour ne pas partir
 * d'une page blanche : un squelette assemblé à partir des exercices du chapitre, ou un brouillon
 * rédigé par le modèle. Dans les deux cas, l'auteur relit et réécrit.
 */
final class LessonDrafter
{
    /** L'outil impose la forme de la réponse : le markdown de la fiche, rien d'autre. */
    private const array OUTIL = [
        'name' => 'ecrire_fiche',
        'description' => 'Écrit la fiche de cours du chapitre.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'fiche' => ['type' => 'string', 'description' => 'Le contenu complet de lesson.md (markdown, sans titre de niveau 1).'],
            ],
            'required' => ['fiche'],
        ],
    ];

    public function __construct(
        private readonly ContentRepository $content,
        private readonly ModelClient $modele,
        private readonly EnvironmentRegistry $environments,
    ) {
    }

    public function chemin(Track $track, Chapter $chapter): string
    {
        return sprintf('%s/chapters/%s/lesson.md', $track->directory, $chapter->id);
    }

    /**
     * Squelette mécanique : concepts, une section vide par concept, les « Rappel » des consignes,
     * les liens de documentation. Les commentaires HTML guident l'auteur et disparaissent au rendu.
     */
    public function squelette(Track $track, Chapter $chapter): string
    {
        $exercises = $this->exercises($track, $chapter);
        $parConcept = [];
        foreach ($exercises as $exercise) {
            foreach ($exercise->concepts as $concept) {
                $parConcept[$concept][] = $exercise->id;
            }
        }

        $lignes = ['## Ce que vous avez appris', ''];
        foreach ($parConcept as $concept => $ids) {
            $lignes[] = sprintf('- **%s** <!-- %s -->', $concept, implode(', ', $ids));
        }
        foreach ($parConcept as $concept => $ids) {
            $lignes[] = '';
            $lignes[] = sprintf('## %s', $concept);
            $lignes[] = '';
            $lignes[] = sprintf('<!-- Vu dans : %s. L\'idée en deux phrases, puis l\'extrait de code clé (bloc ```php). -->', implode(', ', $ids));
        }

        $rappels = [];
        foreach ($exercises as $exercise) {
            if (null !== ($rappel = self::rappel($exercise->instructions))) {
                $rappels[] = sprintf("### %s\n\n%s", $exercise->title, $rappel);
            }
        }
        if ($rappels) {
            $lignes[] = '';
            $lignes[] = '## Rappels vus dans les exercices';
            $lignes[] = '';
            $lignes[] = '<!-- Recopiés des consignes : à fondre dans les sections ci-dessus, puis à supprimer. -->';
            $lignes[] = '';
            $lignes[] = implode("\n\n", $rappels);
        }

        $lignes[] = '';
        $lignes[] = '## Les pièges';
        $lignes[] = '';
        $lignes[] = '- ';

        $docs = [];
        foreach ($exercises as $exercise) {
            foreach ($exercise->docs as $doc) {
                $docs[$doc->url] ??= $doc->title;
            }
        }
        if ($docs) {
            $lignes[] = '';
            $lignes[] = '## Pour aller plus loin';
            $lignes[] = '';
            foreach ($docs as $url => $titre) {
                $lignes[] = sprintf('- [%s](%s)', $titre, $url);
            }
        }

        return implode("\n", $lignes)."\n";
    }

    /** Brouillon rédigé par le modèle, à partir des consignes, solutions et liens des exercices. */
    public function brouillon(Track $track, Chapter $chapter): string
    {
        $exercises = $this->exercises($track, $chapter);
        $framework = $this->environments->get($chapter->environment ?? $track->environment)->framework;
        $blocs = [sprintf("Parcours « %s » : %s\n\nChapitre « %s » (%d exercices).", $track->title, trim($track->description), $chapter->title, \count($exercises))];
        foreach ($exercises as $exercise) {
            $solution = array_filter(
                $this->content->solutionFiles($exercise),
                static fn (string $chemin) => ExerciseDrafter::estDuCode($chemin, $framework),
                \ARRAY_FILTER_USE_KEY,
            );
            $docs = array_map(static fn ($d) => sprintf('- [%s](%s)', $d->title, $d->url), $exercise->docs);
            $blocs[] = implode("\n\n", array_filter([
                sprintf("=== Exercice %s — %s ===\nConcepts : %s", $exercise->id, $exercise->title, implode(', ', $exercise->concepts)),
                "Consignes :\n".trim($exercise->instructions),
                $solution ? "Solution de référence :\n".implode("\n\n", array_map(static fn ($c, $f) => sprintf("--- %s ---\n%s", $c, $f), array_keys($solution), $solution)) : null,
                $docs ? "Documentation :\n".implode("\n", $docs) : null,
            ]));
        }
        $blocs[] = 'Rédige maintenant la fiche de cours de ce chapitre.';

        $entree = $this->modele->appeler($this->consignes($framework), [['role' => 'user', 'content' => implode("\n\n", $blocs)]], self::OUTIL);
        $fiche = $entree['fiche'] ?? null;
        if (!\is_string($fiche) || '' === trim($fiche)) {
            throw new ContentException('Le modèle n\'a pas renvoyé de fiche.');
        }
        // Le titre du chapitre est affiché par le moteur : un « # Titre » en tête serait en double.
        $fiche = (string) preg_replace('/^# [^\n]*\n+/', '', ltrim($fiche));

        return rtrim($fiche)."\n";
    }

    /** Écrit la fiche dans le pack. Refuse d'écraser une fiche existante sans $force. */
    public function ecrire(Track $track, Chapter $chapter, string $markdown, bool $force = false): string
    {
        $chemin = $this->chemin($track, $chapter);
        if (is_file($chemin) && !$force) {
            throw new ContentException(sprintf('%s existe déjà : relisez-le, ou passez --force pour le remplacer.', $chemin));
        }
        if (!is_writable($track->directory)) {
            throw new ContentException(sprintf('Le parcours « %s » est en lecture seule (%s).', $track->id, $track->directory));
        }
        (new Filesystem())->dumpFile($chemin, $markdown);
        $this->content->reset();

        return $chemin;
    }

    /** Retire la fiche du pack : le chapitre n'en a plus. */
    public function supprimer(Track $track, Chapter $chapter): void
    {
        if (!is_writable($track->directory)) {
            throw new ContentException(sprintf('Le parcours « %s » est en lecture seule (%s).', $track->id, $track->directory));
        }
        $chemin = $this->chemin($track, $chapter);
        $filesystem = new Filesystem();
        $filesystem->remove($chemin);
        // Le dossier du chapitre ne sert qu'à la fiche : on ne laisse pas un dossier vide.
        if (is_dir(\dirname($chemin)) && !glob(\dirname($chemin).'/*')) {
            $filesystem->remove(\dirname($chemin));
        }
        $this->content->reset();
    }

    /** La section « ## Rappel » des consignes d'un exercice, s'il y en a une. */
    public static function rappel(string $instructions): ?string
    {
        if (!preg_match('/^## Rappel[^\n]*\n(.*?)(?=^## |\z)/msu', $instructions, $m)) {
            return null;
        }
        $texte = trim($m[1]);

        return '' === $texte ? null : $texte;
    }

    /** @return list<Exercise> */
    private function exercises(Track $track, Chapter $chapter): array
    {
        return array_values(array_filter(array_map(fn (string $id) => $this->content->findExercise($track->id, $id), $chapter->exerciseIds)));
    }

    private function consignes(FrameworkProfile $framework): string
    {
        return str_replace(['{framework}', '{langues}'], [$framework->label, $framework->lessonLanguages], <<<'TEXTE'
            Tu rédiges la fiche de cours de fin de chapitre d'une formation {framework} interactive : l'apprenant
            vient de réussir les exercices du chapitre dans son navigateur, et emporte cette fiche (page web
            et PDF) comme support à garder sous la main. Tu réponds uniquement en appelant l'outil ecrire_fiche.

            Ce qu'est une bonne fiche :
            - Elle explique les concepts du chapitre, pas les exercices : on doit pouvoir la relire six mois
              plus tard sans se souvenir des consignes. L'univers du parcours peut servir d'exemple, sans
              raconter l'histoire.
            - Structure : « ## Ce que vous avez appris » (liste courte), une section « ## » par concept avec
              l'idée en quelques phrases et l'extrait de code clé tiré des solutions, « ## Les pièges »
              (erreurs fréquentes, comportements surprenants), « ## Pour aller plus loin » (les liens de
              documentation fournis, uniquement ceux-là).
            - Longueur : entre 400 et 900 mots hors code. Précis, sans remplissage.

            Règles de forme (le moteur les impose) :
            - Markdown seulement, pas de HTML. Pas de titre de niveau 1 : la fiche commence à « ## ».
            - Blocs de code avec leur langue ({langues}). Le code vient des
              solutions fournies, adapté au minimum ; n'invente pas d'API {framework}.
            - Tout en français, vouvoiement, ton direct.
            TEXTE);
    }
}
