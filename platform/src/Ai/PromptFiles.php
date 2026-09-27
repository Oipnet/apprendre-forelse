<?php

namespace App\Ai;

/** Des fichiers mis en texte pour un prompt : une seule forme, que le modèle apprend à lire partout. */
final class PromptFiles
{
    /**
     * Chaque fichier sous un en-tête « --- chemin --- » (et non des blocs ``` : une consigne en markdown en contient).
     *
     * @param array<string, string> $files contenu par chemin
     * @param int|null              $max   au-delà, chaque fichier est tronqué
     */
    public static function render(array $files, ?int $max = null): string
    {
        $blocs = [];
        foreach ($files as $chemin => $contenu) {
            $blocs[] = sprintf("--- %s ---\n%s", $chemin, null === $max ? $contenu : self::truncate($contenu, $max));
        }

        return implode("\n\n", $blocs);
    }

    public static function truncate(string $texte, int $max): string
    {
        return mb_strlen($texte) > $max ? mb_substr($texte, 0, $max)."\n… (tronqué)" : $texte;
    }
}
