<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Finaliser une inscription commencée avec un fournisseur (GitHub, Google, LinkedIn) : le pseudo (proposé d'après
 * lui) et le code d'invitation. L'adresse est celle qu'il a vérifiée, et il n'y a pas de mot de passe.
 *
 * @extends AbstractType<array{displayName: string|null, invitationCode: string|null}>
 */
final class ExternalRegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'form.external_registration.pseudo',
                'attr' => ['autocomplete' => 'nickname', 'maxlength' => 40],
                'constraints' => [new Assert\NotBlank(message: 'compte.pseudo.vide'), new Assert\Length(max: 40)],
            ])
            ->add('invitationCode', InvitationCodeType::class, [
                'invite_only' => $options['invite_only'],
                'mapped' => true,
                'help' => $options['invite_only'] ? null : 'form.external_registration.code_aide',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['invite_only' => false, 'translation_domain' => 'compte']);
        $resolver->setAllowedTypes('invite_only', 'bool');
    }
}
