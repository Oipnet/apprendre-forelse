<?php

namespace App\Form;

use App\Content\Track;
use App\Entity\Defense;
use App\Entity\DefenseMode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un créneau de soutenance ouvert par le chef de cohorte. Les règles (parcours proposé, date future, lien https)
 * sont vérifiées par DefenseManagement ; ce formulaire n'en garde que la forme.
 *
 * @extends AbstractType<array{trackId: string, startsAt: \DateTimeImmutable, durationMinutes: int, mode: DefenseMode, place: string}>
 */
final class DefenseSlotType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<Track> $tracks */
        $tracks = $options['tracks'];
        $builder
            ->add('trackId', ChoiceType::class, [
                'label' => 'Parcours',
                'choices' => array_combine(array_map(static fn (Track $t) => $t->title, $tracks), array_map(static fn (Track $t) => $t->id, $tracks)),
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('startsAt', DateTimeType::class, [
                'label' => 'Début',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotNull(message: 'Indiquez la date et l\'heure du créneau.')],
            ])
            ->add('durationMinutes', IntegerType::class, [
                'label' => 'Durée (minutes)',
                'empty_data' => '30',
                'attr' => ['min' => Defense::MIN_DURATION, 'max' => Defense::MAX_DURATION, 'step' => 5],
                'constraints' => [new Assert\NotNull(), new Assert\Range(min: Defense::MIN_DURATION, max: Defense::MAX_DURATION)],
            ])
            ->add('mode', EnumType::class, [
                'label' => 'Modalité',
                'class' => DefenseMode::class,
                'expanded' => true,
                'choice_label' => static fn (DefenseMode $mode) => $mode->label(),
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('place', TextType::class, [
                'label' => 'Lien de la visio, ou adresse et salle',
                'attr' => ['maxlength' => 255, 'placeholder' => 'https://… ou 12 rue de l\'École, salle B204'],
                'constraints' => [new Assert\NotBlank(message: 'Indiquez le lien de la visio, ou l\'adresse et la salle.'), new Assert\Length(max: 255)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('tracks');
        $resolver->setAllowedTypes('tracks', 'array');
    }
}
