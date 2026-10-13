<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le nom à imprimer sur le certificat, confirmé avant la délivrance : il ne changera plus.
 *
 * @extends AbstractType<array{holderName: string}>
 */
final class CertificateClaimType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('holderName', TextType::class, [
            'label' => 'Nom sur le certificat',
            'help' => 'Prénom et nom, tels que vous voulez les voir sur le certificat et sur LinkedIn. Ils ne pourront plus être modifiés.',
            'attr' => ['autocomplete' => 'name', 'maxlength' => 80],
            'constraints' => [
                new Assert\NotBlank(message: 'Indiquez le nom à faire figurer sur le certificat.'),
                new Assert\Length(max: 80),
            ],
        ]);
    }
}
