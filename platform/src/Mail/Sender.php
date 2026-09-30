<?php

namespace App\Mail;

use App\Theme\Theme;
use App\Legal\LegalInfo;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;

/**
 * Qui envoie les emails de la plateforme, et à qui répondre.
 *
 * L'expéditeur vient de MAILER_FROM ; sans nom (« ne-pas-repondre@exemple.fr »), il prend celui de la marque :
 * une instance en marque blanche n'écrit ni depuis une adresse anonyme, ni sous le nom du moteur. La réponse va à
 * l'adresse de contact (CONTACT_EMAIL, à défaut celle des mentions légales) : l'expéditeur est rarement lu.
 */
final readonly class Sender
{
    public function __construct(
        private Theme $theme,
        private LegalInfo $legal,
        #[Autowire(env: 'MAILER_FROM')]
        private string $mailerFrom,
        #[Autowire(env: 'CONTACT_EMAIL')]
        private string $contactEmail,
    ) {
    }

    public function from(): Address
    {
        $from = Address::create($this->mailerFrom);

        return '' === $from->getName() ? new Address($from->getAddress(), $this->theme->name()) : $from;
    }

    /** L'adresse qui lit les messages (formulaire de contact, réponses aux emails) ; vide si aucune n'est configurée. */
    public function contact(): string
    {
        return trim($this->contactEmail) ?: trim($this->legal->publisherEmail);
    }

    /** Un email aux apprenants : l'expéditeur, et la réponse vers l'adresse de contact quand il y en a une. */
    public function email(): TemplatedEmail
    {
        $email = (new TemplatedEmail())->from($this->from());
        if ('' !== $this->contact()) {
            $email->replyTo(new Address($this->contact(), $this->theme->name()));
        }

        return $email;
    }
}
