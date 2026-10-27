<?php

namespace App\Scratch;

use App\Content\Exercise;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le projet de départ du bac à sable, présenté au playground comme un exercice sans objectif ni test : il en réutilise
 * l'éditeur, la console et l'aperçu. Ses fichiers vivent dans le moteur (resources/bac-a-sable/<framework>/starter),
 * pas dans un pack : chaque instance a son bac à sable, sans rien écrire.
 *
 * En anglais, `i18n/en/` remplace la consigne et, chemin par chemin, les fichiers de départ (comme une traduction
 * d'exercice, voir Exercise::$overlay).
 */
final readonly class ScratchProjects
{
    public function __construct(
        private TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%/resources/bac-a-sable')]
        private string $directory,
    ) {
    }

    public function exercise(ScratchFramework $framework, string $locale = Exercise::LOCALE): Exercise
    {
        $directory = $this->directory.'/'.$framework->value;
        $overlay = Exercise::LOCALE === $locale ? null : $directory.'/i18n/'.$locale;
        if (null !== $overlay && !is_dir($overlay)) {
            $overlay = null;
        }

        return new Exercise(
            id: 'bac-a-sable-'.$framework->value,
            trackId: null,
            title: $this->translator->trans('bac_a_sable.projet.titre', ['framework' => $framework->label()], 'apprentissage', $locale),
            concepts: [],
            xp: 0,
            environment: $framework->environment(),
            base: null,
            open: $framework->open(),
            preview: '/',
            editable: $framework->editable(),
            readonly: [],
            objectives: [],
            hints: [],
            instructions: (string) file_get_contents(($overlay ?? $directory).'/instructions.md'),
            directory: $directory,
            locale: null === $overlay ? Exercise::LOCALE : $locale,
            overlay: $overlay,
        );
    }
}
