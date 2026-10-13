<?php

namespace App\Form;

use App\Validator\StrongPassword;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Nouveau mot de passe, mêmes règles qu'à l'inscription (voir RegistrationFormType).
 *
 * @extends AbstractType<array{plainPassword: string|null}>
 */
final class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', PasswordType::class, [
            'label' => 'form.change_password.nouveau',
            'attr' => ['autocomplete' => 'new-password', 'autofocus' => true],
            'constraints' => [new StrongPassword()],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'compte']);
    }
}
