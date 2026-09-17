<?php

namespace App\Form\Account;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/** Changer de mot de passe depuis son compte : l'actuel d'abord, puis le nouveau (mêmes règles qu'à l'inscription). */
final class PasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new UserPassword(message: 'Ce n\'est pas votre mot de passe actuel.')],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Nouveau mot de passe',
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Choisissez un mot de passe.'),
                    new Assert\Length(min: 8, max: 4096, minMessage: 'Au moins {{ limit }} caractères.'),
                ],
            ]);
    }
}
