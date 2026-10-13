<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le nom à imprimer sur le certificat, confirmé avant la délivrance : il ne changera plus.
 *
 * @extends AbstractType<array{holderName: string}>
 */
final class CertificateClaimType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('holderName', TextType::class, [
            'label' => 'claim.form.nom',
            'help' => 'claim.form.nom_aide',
            'attr' => ['autocomplete' => 'name', 'maxlength' => 80],
            'constraints' => [
                new Assert\NotBlank(message: 'certificate_claim.holder_name.not_blank'),
                new Assert\Length(max: 80),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'certificat']);
    }
}
