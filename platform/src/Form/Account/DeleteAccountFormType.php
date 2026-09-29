<?php

namespace App\Form\Account;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;

/**
 * Supprimer son compte : le mot de passe confirme que c'est bien le titulaire, et pas un onglet resté ouvert. Un compte
 * sans mot de passe (créé avec un fournisseur) repasse par un fournisseur lié à la place (voir RecentSignIn) : le formulaire est vide.
 *
 * @extends AbstractType<array{password: string|null}>
 */
final class DeleteAccountFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['with_password']) {
            $builder->add('password', PasswordType::class, [
                'label' => 'Mot de passe',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new UserPassword(message: 'Ce n\'est pas votre mot de passe.')],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['with_password' => true]);
        $resolver->setAllowedTypes('with_password', 'bool');
    }
}
