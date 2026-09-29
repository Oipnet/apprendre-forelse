<?php

namespace App\Form;

use App\Entity\User;
use App\Validator\StrongPassword;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<User> */
final class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, ['label' => 'Pseudo', 'attr' => ['autocomplete' => 'nickname']])
            ->add('email', EmailType::class, ['label' => 'Email', 'attr' => ['autocomplete' => 'email']])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Mot de passe',
                'mapped' => false,
                'attr' => ['autocomplete' => 'new-password'],
                'help' => 'Une phrase de plusieurs mots se retient mieux et résiste plus longtemps qu\'un mot compliqué.',
                'constraints' => [new StrongPassword()],
            ]);

        // Le champ apparaît si l'inscription est sur invitation, ou si un lien d'invitation a fourni un code.
        if ($options['invite_only'] || null !== $options['invitation_code']) {
            $builder->add('invitationCode', InvitationCodeType::class, [
                'invite_only' => $options['invite_only'],
                'data' => $options['invitation_code'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'invite_only' => false,
            'invitation_code' => null,
        ]);
        $resolver->setAllowedTypes('invite_only', 'bool');
        $resolver->setAllowedTypes('invitation_code', ['null', 'string']);
    }
}
