<?php

namespace App\Form\Account;

use App\Validator\StrongPassword;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;

/**
 * Changer de mot de passe depuis son compte : l'actuel d'abord, puis le nouveau (mêmes règles qu'à l'inscription).
 *
 * @extends AbstractType<array{currentPassword: string|null, plainPassword: string|null}>
 */
final class PasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'form.password.actuel',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new UserPassword(message: 'compte.mot_de_passe.actuel_incorrect')],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'form.password.nouveau',
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [new StrongPassword()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'compte']);
    }
}
