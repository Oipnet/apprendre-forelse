<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** Nouveau mot de passe, mêmes règles qu'à l'inscription (voir RegistrationFormType). */
final class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', PasswordType::class, [
            'label' => 'Nouveau mot de passe',
            'attr' => ['autocomplete' => 'new-password', 'autofocus' => true],
            'constraints' => [
                new Assert\NotBlank(message: 'Choisissez un mot de passe.'),
                new Assert\Length(min: 8, max: 4096, minMessage: 'Au moins {{ limit }} caractères.'),
            ],
        ]);
    }
}
