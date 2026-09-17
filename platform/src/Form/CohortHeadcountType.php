<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** Effectif prévu d'une cohorte, seul champ du financement que le chef de cohorte modifie. */
final class CohortHeadcountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('expectedHeadcount', IntegerType::class, [
            'label' => 'Effectif prévu',
            'attr' => ['min' => 0, 'max' => 10000],
            'constraints' => [new Assert\NotNull(), new Assert\Range(min: 0, max: 10000)],
        ]);
    }
}
