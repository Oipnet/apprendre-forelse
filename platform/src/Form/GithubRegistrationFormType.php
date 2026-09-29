<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Finaliser une inscription commencée avec GitHub : le pseudo (proposé d'après GitHub) et le code d'invitation.
 * L'adresse est celle que GitHub a vérifiée, et il n'y a pas de mot de passe.
 *
 * @extends AbstractType<array{displayName: string|null, invitationCode: string|null}>
 */
final class GithubRegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'Pseudo',
                'attr' => ['autocomplete' => 'nickname', 'maxlength' => 40],
                'constraints' => [new Assert\NotBlank(message: 'Choisissez un pseudo.'), new Assert\Length(max: 40)],
            ])
            ->add('invitationCode', InvitationCodeType::class, [
                'invite_only' => $options['invite_only'],
                'mapped' => true,
                'help' => $options['invite_only'] ? null : 'Facultatif : celui que votre formateur vous a donné, pour rejoindre sa cohorte.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['invite_only' => false]);
        $resolver->setAllowedTypes('invite_only', 'bool');
    }
}
