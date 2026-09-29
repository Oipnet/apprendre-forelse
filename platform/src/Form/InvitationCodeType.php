<?php

namespace App\Form;

use App\Repository\CohortRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Le code d'invitation d'une inscription (avec mot de passe ou avec GitHub) : exigé si l'inscription est sur
 * invitation, et toujours celui d'une cohorte active.
 *
 * @extends AbstractType<string|null>
 */
final class InvitationCodeType extends AbstractType
{
    public function __construct(private readonly CohortRepository $cohorts)
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label' => 'Code d\'invitation',
            'mapped' => false,
            'invite_only' => false,
            'required' => static fn (Options $options): bool => $options['invite_only'],
            'attr' => ['autocomplete' => 'off'],
            'constraints' => fn (Options $options): array => [
                ...($options['invite_only'] ? [new Assert\NotBlank(message: 'Indiquez votre code d\'invitation.')] : []),
                new Assert\Callback(function (?string $code, ExecutionContextInterface $context): void {
                    if (null !== $code && '' !== trim($code) && null === $this->cohorts->findActiveByCode($code)) {
                        $context->buildViolation('Code d\'invitation inconnu ou expiré.')->addViolation();
                    }
                }),
            ],
        ]);
        $resolver->setAllowedTypes('invite_only', 'bool');
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
