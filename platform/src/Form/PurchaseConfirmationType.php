<?php

namespace App\Form;

use App\Payment\WithdrawalWaiver;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Confirmation avant Stripe : acceptation des CGV et renonciation au droit de rétractation, toutes deux obligatoires.
 *
 * @extends AbstractType<array{termsOfSale: bool, withdrawalWaiver: bool}>
 */
final class PurchaseConfirmationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('termsOfSale', CheckboxType::class, [
                'label' => 'form.purchase_confirmation.cgv',
                'label_translation_parameters' => ['lien' => htmlspecialchars($options['terms_url'])],
                'label_html' => true,
                'constraints' => [new Assert\IsTrue(message: 'achat.cgv')],
            ])
            ->add('withdrawalWaiver', CheckboxType::class, [
                // Le texte conservé avec l'achat, tel qu'il a été coché (voir WithdrawalWaiver) : il n'est pas traduit.
                'label' => WithdrawalWaiver::TEXT,
                'translation_domain' => false,
                'constraints' => [new Assert\IsTrue(message: 'achat.renonciation')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('terms_url')->setAllowedTypes('terms_url', 'string');
        $resolver->setDefaults(['translation_domain' => 'compte']);
    }
}
