<?php

namespace App\Content;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Lit un article du blog et le valide. Le rangement (doublons entre packs, traductions) reste à PackReader, qui
 * voit tous les packs.
 */
final readonly class ArticleReader
{
    public function __construct(private PackFiles $files)
    {
    }

    /** Les clés de l'en-tête d'un article : une autre (« date », « auteur »…) est une faute de frappe, refusée. */
    private const array ARTICLE_KEYS = ['title', 'description', 'published', 'updated', 'visibility', 'practice', 'tags'];
    /** Une traduction nomme en plus l'article français qu'elle traduit. */
    private const array TRANSLATION_KEYS = [...self::ARTICLE_KEYS, 'translation_of'];

    /**
     * Un article du blog : `<pack>/articles/<slug>.md`, un en-tête YAML entre deux lignes « --- », puis le
     * Markdown. Le nom du fichier est l'adresse de l'article : le changer casse les liens qui y mènent.
     */
    public function read(string $file, string $locale, string $packId): Article
    {
        $slug = basename($file, '.md');
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
            throw new ContentException(sprintf('Article « %s » : le nom du fichier est son adresse, en minuscules et sans accent (« symfony-dans-le-navigateur.md »).', $file));
        }
        $this->files->watch($file);
        if (!preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n(.*)\z/s', (string) file_get_contents($file), $parts)) {
            throw new ContentException(sprintf('%s : un article commence par son en-tête YAML, entre deux lignes « --- ».', $file));
        }
        try {
            $meta = Yaml::parse($parts[1]);
        } catch (ParseException $e) {
            throw new ContentException(sprintf('%s : en-tête YAML invalide, %s', $file, lcfirst($e->getMessage())), previous: $e);
        }
        if (!\is_array($meta)) {
            throw new ContentException(sprintf('%s : en-tête YAML invalide.', $file));
        }
        $keys = Article::LOCALE === $locale ? self::ARTICLE_KEYS : self::TRANSLATION_KEYS;
        if ([] !== ($unknown = array_diff(array_keys($meta), $keys))) {
            throw new ContentException(sprintf('%s : clé « %s » inconnue (clés possibles : %s).', $file, implode(' », « ', $unknown), implode(', ', $keys)));
        }
        $markdown = trim($parts[2]);
        if ('' === $markdown) {
            throw new ContentException(sprintf('%s : article sans texte.', $file));
        }

        $published = ContentFields::date($meta['published'] ?? null, $file, 'published');
        $updated = isset($meta['updated']) ? ContentFields::date($meta['updated'], $file, 'updated') : null;
        if (null !== $updated && $updated < $published) {
            throw new ContentException(sprintf('%s : « updated » précède « published ».', $file));
        }
        $visibility = ContentFields::visibility($meta, $file);

        return new Article(
            slug: $slug,
            packId: $packId,
            title: (string) ContentFields::optionalText($this->files->required($meta, 'title', $file), $file.' : « title »'),
            description: (string) ContentFields::optionalText($this->files->required($meta, 'description', $file), $file.' : « description »'),
            published: $published,
            markdown: $markdown,
            file: $file,
            updated: $updated,
            visibility: $visibility,
            locale: $locale,
            translationOf: Article::LOCALE === $locale ? null : $this->files->required($meta, 'translation_of', $file),
            practice: isset($meta['practice']) ? (string) $meta['practice'] : null,
            tags: self::tags($meta['tags'] ?? [], $file),
        );
    }

    /**
     * Les sujets d'un article : une liste de libellés courts (`tags: [Symfony, Sécurité]`), sans doublon. Un libellé
     * est aussi le filtre de la liste (?sujet=securite) : deux libellés qui donnent le même filtre sont refusés.
     *
     * @return list<string>
     */
    private static function tags(mixed $value, string $file): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new ContentException(sprintf('%s : « tags » est une liste de sujets (tags: [Symfony, Sécurité]).', $file));
        }
        $tags = [];
        foreach ($value as $tag) {
            $tag = \is_string($tag) ? trim($tag) : '';
            if ('' === $tag || '' === Blog::topic($tag)) {
                throw new ContentException(sprintf('%s : chaque sujet de « tags » est un texte court (tags: [Symfony, Sécurité]).', $file));
            }
            if (isset($tags[Blog::topic($tag)])) {
                throw new ContentException(sprintf('%s : sujet « %s » donné deux fois dans « tags ».', $file, $tag));
            }
            $tags[Blog::topic($tag)] = $tag;
        }

        return array_values($tags);
    }
}
