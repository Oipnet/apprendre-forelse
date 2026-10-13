<?php

namespace App\Form;

use App\Entity\ContactMessage;
use App\Entity\ContactSubject;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de contact. Pour une école ou une entreprise (`organization` à true), l'objet est fixé et on demande
 * l'établissement et l'effectif à la place.
 *
 * @extends AbstractType<ContactMessage>
 */
final class ContactFormType extends AbstractType
{
    /** Champ piège : invisible pour un humain, rempli par les robots. */
    public const string TRAP = 'site';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$options['organization']) {
            $builder->add('subject', EnumType::class, [
                'label' => 'form.contact.objet',
                'class' => ContactSubject::class,
                // L'objet est traduisible (compte : enum.contact_subject.*).
                'choice_label' => static fn (ContactSubject $subject) => $subject,
            ]);
        }
        $builder
            ->add('name', TextType::class, ['label' => 'form.contact.nom', 'attr' => ['autocomplete' => 'name', 'maxlength' => 100]])
            ->add('email', EmailType::class, ['label' => 'form.contact.email', 'attr' => ['autocomplete' => 'email', 'maxlength' => 180]]);
        if ($options['organization']) {
            $builder
                ->add('organization', TextType::class, ['label' => 'form.contact.organisation', 'required' => false, 'attr' => ['autocomplete' => 'organization', 'maxlength' => 150]])
                ->add('headcount', IntegerType::class, ['label' => 'form.contact.effectif', 'required' => false, 'attr' => ['min' => 1]]);
        }
        $builder
            ->add('message', TextareaType::class, [
                'label' => $options['organization'] ? 'form.contact.projet' : 'form.contact.message',
                'attr' => [
                    'rows' => 7,
                    'maxlength' => 5000,
                    'placeholder' => $options['organization'] ? 'form.contact.projet_exemple' : '',
                ],
            ])
            ->add(self::TRAP, TextType::class, ['mapped' => false, 'required' => false, 'label' => false, 'attr' => ['class' => 'lp-trap', 'tabindex' => -1, 'autocomplete' => 'off', 'aria-hidden' => 'true']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ContactMessage::class, 'organization' => false, 'translation_domain' => 'compte']);
        $resolver->setAllowedTypes('organization', 'bool');
    }
}
