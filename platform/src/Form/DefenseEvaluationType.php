<?php

namespace App\Form;

use App\Content\Chapter;
use App\Entity\DefenseLevel;
use App\Entity\DefenseOutcome;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * La grille d'une soutenance : un niveau par chapitre du parcours (champ « c0 », « c1 »… dans l'ordre des chapitres :
 * un identifiant de chapitre n'est pas forcément un nom de champ valide), le résultat et un commentaire.
 *
 * @extends AbstractType<array{grades: array<string, DefenseLevel>, outcome: DefenseOutcome, comment: ?string}>
 */
final class DefenseEvaluationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $grades = $builder->create('grades', FormType::class, ['label' => false]);
        /** @var list<Chapter> $chapters */
        $chapters = $options['chapters'];
        foreach ($chapters as $index => $chapter) {
            $grades->add('c'.$index, EnumType::class, [
                'label' => $chapter->title,
                'class' => DefenseLevel::class,
                'expanded' => true,
                'choice_label' => static fn (DefenseLevel $level) => $level->label(),
                'constraints' => [new Assert\NotNull(message: 'Choisissez un niveau pour ce chapitre.')],
            ]);
        }
        $builder
            ->add($grades)
            ->add('outcome', EnumType::class, [
                'label' => 'Résultat',
                'class' => DefenseOutcome::class,
                'expanded' => true,
                'choice_label' => static fn (DefenseOutcome $outcome) => $outcome->label(),
                'constraints' => [new Assert\NotNull(message: 'Choisissez le résultat.')],
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Commentaire pour l\'apprenant',
                'required' => false,
                'attr' => ['rows' => 5, 'maxlength' => 5000],
                'constraints' => [new Assert\Length(max: 5000)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('chapters');
        $resolver->setAllowedTypes('chapters', 'array');
    }
}
