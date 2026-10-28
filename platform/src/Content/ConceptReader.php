<?php

namespace App\Content;

use App\Content\Framework\FrameworkRegistry;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Lit ce qu'un pack dit des notions : l'intro d'une page de notion et le rangement de l'index en thèmes. Les
 * doublons entre packs restent à PackReader, qui voit tous les packs.
 */
final readonly class ConceptReader
{
    public function __construct(
        private PackFiles $files,
        private FrameworkRegistry $frameworks,
    ) {
    }

    /**
     * L'intro d'une page de notion : `<pack>/notions/controleur.md`, du Markdown sans titre. Le nom du fichier est
     * l'identifiant de la notion dans l'adresse (voir ConceptIndex::slug). Même règles que l'intro d'une version :
     * une faute de frappe dans le nom est refusée, et content:check signale une intro que rien n'affiche.
     *
     * Un en-tête YAML facultatif (entre deux lignes « --- ») peut donner `title` : le nom de la page tel qu'on le
     * cherche (« Voter Symfony : contrôler l'accès à un objet »), là où l'étiquette des exercices reste courte (« Voter »).
     *
     * Une notion d'une autre langue a son intro dans `<pack>/notions/<langue>/`, nommée comme son adresse dans cette
     * langue (`notions/en/controller.md` pour /en/concepts/controller), au même format.
     *
     * @return array{markdown: string, file: string, packId: string, title: string|null}
     */
    public function intro(string $file, string $packId): array
    {
        $slug = basename($file, '.md');
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
            throw new ContentException(sprintf('Intro de notion « %s » : le nom du fichier doit être l\'identifiant de la notion dans l\'adresse, en minuscules (« controleur.md »).', $file));
        }
        $this->files->watch($file);
        $markdown = (string) file_get_contents($file);
        $title = null;
        if (preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n(.*)\z/s', $markdown, $parts)) {
            try {
                $meta = Yaml::parse($parts[1]);
            } catch (ParseException $e) {
                throw new ContentException(sprintf('%s : en-tête YAML invalide, %s', $file, lcfirst($e->getMessage())), previous: $e);
            }
            if (!\is_array($meta) || [] !== array_diff(array_keys($meta), ['title']) || !\is_string($meta['title'] ?? '')) {
                throw new ContentException(sprintf('%s : l\'en-tête d\'une intro de notion ne connaît que « title », un texte.', $file));
            }
            $title = isset($meta['title']) ? trim($meta['title']) : null;
            $markdown = $parts[2];
        }
        $markdown = trim($markdown);
        if ('' === $markdown) {
            throw new ContentException(sprintf('Intro de notion « %s » vide : retirez le fichier, la page reste alors hors des moteurs de recherche.', $file));
        }

        return ['markdown' => $markdown, 'file' => $file, 'packId' => $packId, 'title' => '' === $title ? null : $title];
    }

    /** Les clés d'un thème de notions : une autre est une faute de frappe, refusée. */
    private const array CONCEPT_THEME_KEYS = ['title', 'description', 'notions'];

    /**
     * Le rangement de l'index des notions : `<pack>/notions/themes.yaml`, une liste de thèmes par framework. Chaque
     * thème a un titre, une phrase qui dit ce qu'il couvre et ses notions, par le libellé que leur donnent les
     * exercices (`concepts:`). Une notion qu'aucun exercice publié ne pratique encore s'annonce « bientôt » ; une
     * notion qu'aucun thème ne range tombe dans « Autres notions ». Un seul pack range les notions d'un framework.
     *
     * Dans une autre langue, `<pack>/notions/<langue>/themes.yaml` range les notions par leur libellé dans cette
     * langue, celui des exercices traduits. Sans ce fichier, l'index de cette langue est une seule liste.
     *
     * @return array<string, list<ConceptTheme>> les thèmes, par framework
     */
    public function themes(string $file, string $locale): array
    {
        $frameworks = $this->frameworks;
        $all = [];
        foreach ($this->files->parse($file) as $framework => $themes) {
            $framework = (string) $framework;
            if (!$frameworks->has($framework)) {
                throw new ContentException(sprintf('%s : framework « %s » inconnu (%s).', $file, $framework, implode(', ', $frameworks->ids())));
            }
            if (!\is_array($themes) || !array_is_list($themes) || [] === $themes) {
                throw new ContentException(sprintf('%s : « %s » est une liste de thèmes (title, description, notions).', $file, $framework));
            }
            $list = [];
            $placed = [];
            foreach ($themes as $i => $theme) {
                $where = sprintf('%s : %s, thème n° %d', $file, $framework, $i + 1);
                if (!\is_array($theme)) {
                    throw new ContentException(sprintf('%s : un thème a un titre, une description et des notions (title, description, notions).', $where));
                }
                if ([] !== $unknown = array_diff(array_keys($theme), self::CONCEPT_THEME_KEYS)) {
                    throw new ContentException(sprintf('%s : clé « %s » inconnue (un thème ne connaît que title, description et notions).', $where, implode(' », « ', $unknown)));
                }
                $title = trim($this->files->required($theme, 'title', $where));
                $id = ConceptIndex::slug($title, $locale);
                if (isset($list[$id])) {
                    throw new ContentException(sprintf('%s : thème « %s » déclaré deux fois.', $where, $title));
                }
                $concepts = $theme['notions'] ?? null;
                if (!\is_array($concepts) || !array_is_list($concepts) || [] === $concepts) {
                    throw new ContentException(sprintf('%s : « notions » est la liste des notions du thème, par leur libellé.', $where));
                }
                foreach ($concepts as $concept) {
                    if (!\is_string($concept) || '' === trim($concept)) {
                        throw new ContentException(sprintf('%s : une notion est un libellé, comme dans les « concepts » des exercices.', $where));
                    }
                    $slug = ConceptIndex::slug($concept, $locale);
                    if (isset($placed[$slug])) {
                        throw new ContentException(sprintf('%s : la notion « %s » est déjà rangée dans « %s ».', $where, $concept, $placed[$slug]));
                    }
                    $placed[$slug] = $title;
                }
                $list[$id] = new ConceptTheme($id, $title, trim((string) ($theme['description'] ?? '')), array_map('trim', $concepts));
            }
            $all[$framework] = array_values($list);
        }

        return $all;
    }
}
