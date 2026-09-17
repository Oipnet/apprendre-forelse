<?php

namespace App\Form;

use App\Entity\User;
use App\Repository\CohortRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** @extends AbstractType<User> */
final class RegistrationFormType extends AbstractType
{
    public function __construct(private readonly CohortRepository $cohorts)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, ['label' => 'Pseudo', 'attr' => ['autocomplete' => 'nickname']])
            ->add('email', EmailType::class, ['label' => 'Email', 'attr' => ['autocomplete' => 'email']])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Mot de passe',
                'mapped' => false,
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Choisissez un mot de passe.'),
                    new Assert\Length(min: 8, max: 4096, minMessage: 'Au moins {{ limit }} caractères.'),
                ],
            ]);

        // Le champ apparaît si l'inscription est sur invitation, ou si un lien d'invitation a fourni un code.
        if ($options['invite_only'] || null !== $options['invitation_code']) {
            $builder->add('invitationCode', TextType::class, [
                'label' => 'Code d\'invitation',
                'mapped' => false,
                'required' => $options['invite_only'],
                'data' => $options['invitation_code'],
                'attr' => ['autocomplete' => 'off'],
                'constraints' => [
                    ...($options['invite_only'] ? [new Assert\NotBlank(message: 'Indiquez votre code d\'invitation.')] : []),
                    new Assert\Callback(function (?string $code, ExecutionContextInterface $context): void {
                        if (null !== $code && '' !== trim($code) && null === $this->cohorts->findActiveByCode($code)) {
                            $context->buildViolation('Code d\'invitation inconnu ou expiré.')->addViolation();
                        }
                    }),
                ],
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
