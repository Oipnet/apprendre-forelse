<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Mot de passe oublié : l'adresse du compte, rien d'autre.
 *
 * @extends AbstractType<array{email: string|null}>
 */
final class ResetPasswordRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Email',
            'attr' => ['autocomplete' => 'email', 'autofocus' => true],
            'constraints' => [
                new Assert\NotBlank(message: 'Indiquez votre email.'),
                new Assert\Email(message: 'Cet email n\'est pas valide.'),
            ],
        ]);
    }
}
