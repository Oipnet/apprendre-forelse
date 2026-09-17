<?php

namespace App\Form;

use App\Payment\WithdrawalWaiver;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Confirmation avant Stripe : acceptation des CGV et renonciation au droit de rétractation, toutes deux obligatoires. */
final class PurchaseConfirmationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('termsOfSale', CheckboxType::class, [
                'label' => sprintf('J\'ai lu et j\'accepte les <a href="%s" target="_blank" rel="noopener">conditions générales de vente</a>.', htmlspecialchars($options['terms_url'])),
                'label_html' => true,
                'constraints' => [new Assert\IsTrue(message: 'Acceptez les conditions générales de vente pour acheter le parcours.')],
            ])
            ->add('withdrawalWaiver', CheckboxType::class, [
                'label' => WithdrawalWaiver::TEXT,
                'constraints' => [new Assert\IsTrue(message: 'Cochez la case pour accéder au parcours dès le paiement.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('terms_url')->setAllowedTypes('terms_url', 'string');
    }
}
