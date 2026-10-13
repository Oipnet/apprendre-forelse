<?php

namespace App\Form\Account;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Pseudo et adresse du compte. Sans classe de données : un formulaire invalide ne doit pas modifier l'utilisateur
 * connecté, et une nouvelle adresse n'est enregistrée qu'une fois confirmée (voir EmailVerifier).
 *
 * Changer d'adresse demande le mot de passe (vérifié par AccountController) : sur un poste partagé ou avec une
 * session volée, remplacer l'adresse puis réinitialiser le mot de passe suffirait à s'approprier le compte. Un compte
 * sans mot de passe (créé avec un fournisseur) n'a pas ce champ : il repasse par un fournisseur lié (voir RecentSignIn).
 *
 * @extends AbstractType<array{displayName: string, email: string, currentPassword: string|null}>
 */
final class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'form.profile.pseudo',
                'attr' => ['autocomplete' => 'nickname', 'maxlength' => 40],
                'constraints' => [new Assert\NotBlank(message: 'compte.pseudo.vide'), new Assert\Length(max: 40)],
            ])
            ->add('email', EmailType::class, [
                'label' => 'form.profile.email',
                'help' => 'form.profile.email_aide',
                'attr' => ['autocomplete' => 'email', 'maxlength' => 180],
                'constraints' => [
                    new Assert\NotBlank(message: 'compte.email.vide'),
                    new Assert\Email(message: 'email.invalide'),
                    new Assert\Length(max: 180),
                ],
            ]);
        if ($options['with_password']) {
            $builder->add('currentPassword', PasswordType::class, [
                'label' => 'form.profile.mot_de_passe',
                'help' => 'form.profile.mot_de_passe_aide',
                'required' => false,
                'attr' => ['autocomplete' => 'current-password'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['with_password' => true, 'translation_domain' => 'compte']);
        $resolver->setAllowedTypes('with_password', 'bool');
    }
}
