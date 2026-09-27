<?php

namespace App\Content\Author\Scaffold;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Le squelette d'un nouvel exercice pour un framework : les fichiers d'un exercice vide, mais valide.
 *
 * Un par framework, trouvé par l'identifiant de son profil (ExerciseScaffolders) : l'atelier ne connaît
 * aucun framework par son nom. Un framework sans échafaudeur ne se crée pas depuis l'atelier — il faut
 * écrire l'exercice à la main, ou le faire rédiger.
 */
#[AutoconfigureTag(self::TAG)]
interface ExerciseScaffolder
{
    public const string TAG = 'app.exercise_scaffolder';

    /** L'identifiant du profil de framework servi (« symfony »). */
    public static function framework(): string;

    /**
     * @param string $entete  le début d'exercise.yaml, commun à tous les frameworks (id, titre, XP ou clés de Pratique, base)
     * @param bool   $pratique un exercice de Pratique, dont les tests vont dans leur propre dossier
     *
     * @return array<string, string> contenu par chemin relatif, exercise.yaml compris
     */
    public function files(string $entete, string $titre, bool $pratique): array;
}
