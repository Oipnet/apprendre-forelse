<?php

namespace App\Form;

use App\Content\Track;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Parcours proposés par une cohorte : une case par parcours installé. */
final class CohortTracksType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, Track> $tracks */
        $tracks = $options['tracks'];
        $builder->add('trackIds', ChoiceType::class, [
            'label' => false,
            'choices' => array_combine(array_map(static fn (Track $t) => $t->title, $tracks), array_keys($tracks)),
            'multiple' => true,
            'expanded' => true,
            // Case désactivée : le navigateur ne l'envoie pas, le contrôleur conserve son état d'origine.
            'choice_attr' => static fn (string $id) => \in_array($id, $options['locked'], true) ? ['disabled' => 'disabled'] : [],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('tracks')->setAllowedTypes('tracks', 'array');
        $resolver->setDefault('locked', [])->setAllowedTypes('locked', 'string[]');
    }
}
