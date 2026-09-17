<?php

namespace App\Form\Account;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Pseudo et adresse du compte. Sans classe de données : un formulaire invalide ne doit pas modifier l'utilisateur
 * connecté, et une nouvelle adresse n'est enregistrée qu'une fois confirmée (voir EmailVerifier).
 *
 * @extends AbstractType<array{displayName: string, email: string}>
 */
final class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'Pseudo',
                'attr' => ['autocomplete' => 'nickname', 'maxlength' => 40],
                'constraints' => [new Assert\NotBlank(message: 'Choisissez un pseudo.'), new Assert\Length(max: 40)],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'help' => 'Une nouvelle adresse ne remplace l\'actuelle qu\'une fois confirmée par le lien que nous y envoyons.',
                'attr' => ['autocomplete' => 'email', 'maxlength' => 180],
                'constraints' => [
                    new Assert\NotBlank(message: 'Indiquez votre email.'),
                    new Assert\Email(message: 'Cet email n\'est pas valide.'),
                    new Assert\Length(max: 180),
                ],
            ]);
    }
}
