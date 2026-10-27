<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une classe gratuite : son nom (à la création seulement, option with_name) et les exercices de la Pratique qu'elle suit.
 *
 * @extends AbstractType<array{name?: string, practiceIds: list<string>}>
 */
final class FreeClassType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['with_name']) {
            $builder->add('name', TextType::class, [
                'label' => 'Nom de la classe',
                'help' => 'Vos élèves le verront en haut de la Pratique. Par exemple : « BTS SIO 2 — Bordeaux ».',
                'attr' => ['maxlength' => 80],
                'constraints' => [new Assert\NotBlank(message: 'Donnez un nom à votre classe.'), new Assert\Length(max: 80)],
            ]);
        }
        $builder->add('practiceIds', ChoiceType::class, [
            'label' => $options['with_name'] ? 'Exercices de la classe' : false,
            'choices' => $options['practices'],
            'multiple' => true,
            'expanded' => true,
            'constraints' => [new Assert\Count(min: 1, minMessage: 'Choisissez au moins un exercice.')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('practices')->setAllowedTypes('practices', 'array');
        $resolver->setDefault('with_name', true)->setAllowedTypes('with_name', 'bool');
    }
}
