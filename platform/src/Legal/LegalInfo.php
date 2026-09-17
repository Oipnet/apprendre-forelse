<?php

namespace App\Legal;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Qui édite et qui héberge cette instance du moteur : mentions légales et responsable du traitement.
 *
 * Le moteur est open source et auto-hébergeable : rien de tout cela n'est écrit dans les pages,
 * tout vient des variables d'environnement LEGAL_* (voir .env). Une valeur vide n'est pas affichée ;
 * les pages signalent ce qui manque plutôt que d'inventer.
 */
final class LegalInfo
{
    public function __construct(
        /** Dénomination sociale, ou nom et prénom pour un entrepreneur individuel. */
        #[Autowire(env: 'LEGAL_PUBLISHER_NAME')]
        public readonly string $publisherName = '',
        /** Forme juridique et capital : « SAS au capital de 1 000 € », « Entrepreneur individuel ». */
        #[Autowire(env: 'LEGAL_PUBLISHER_LEGAL_FORM')]
        public readonly string $publisherLegalForm = '',
        /** Immatriculation : « RCS Lyon 123 456 789 », « SIREN 123 456 789 ». */
        #[Autowire(env: 'LEGAL_PUBLISHER_REGISTRATION')]
        public readonly string $publisherRegistration = '',
        /** Numéro de TVA intracommunautaire (facultatif). */
        #[Autowire(env: 'LEGAL_PUBLISHER_VAT')]
        public readonly string $publisherVat = '',
        /** Siège social, sur une ligne. */
        #[Autowire(env: 'LEGAL_PUBLISHER_ADDRESS')]
        public readonly string $publisherAddress = '',
        /** Adresse de contact : mentions légales et exercice des droits RGPD. */
        #[Autowire(env: 'LEGAL_PUBLISHER_EMAIL')]
        public readonly string $publisherEmail = '',
        /** Téléphone (facultatif). */
        #[Autowire(env: 'LEGAL_PUBLISHER_PHONE')]
        public readonly string $publisherPhone = '',
        /** Directeur ou directrice de la publication : en général le représentant légal. */
        #[Autowire(env: 'LEGAL_PUBLICATION_DIRECTOR')]
        public readonly string $publicationDirector = '',
        /** Hébergeur du serveur : raison sociale. */
        #[Autowire(env: 'LEGAL_HOST_NAME')]
        public readonly string $hostName = '',
        /** Adresse de l'hébergeur, sur une ligne. */
        #[Autowire(env: 'LEGAL_HOST_ADDRESS')]
        public readonly string $hostAddress = '',
        /** Téléphone de l'hébergeur (facultatif). */
        #[Autowire(env: 'LEGAL_HOST_PHONE')]
        public readonly string $hostPhone = '',
        /** Médiateur de la consommation auquel l'éditeur adhère (obligatoire pour vendre à des particuliers). */
        #[Autowire(env: 'LEGAL_MEDIATOR_NAME')]
        public readonly string $mediatorName = '',
        /** Site du médiateur, où le consommateur dépose sa réclamation. */
        #[Autowire(env: 'LEGAL_MEDIATOR_URL')]
        public readonly string $mediatorUrl = '',
        /** Garantie commerciale « satisfait ou remboursé », en jours après l'achat ; vide : pas de garantie. */
        #[Autowire(env: 'LEGAL_REFUND_DAYS')]
        public readonly string $refundDays = '',
    ) {
    }

    /**
     * Les variables obligatoires laissées vides, pour le signaler sur la page.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $required = [
            'LEGAL_PUBLISHER_NAME' => $this->publisherName,
            'LEGAL_PUBLISHER_LEGAL_FORM' => $this->publisherLegalForm,
            'LEGAL_PUBLISHER_REGISTRATION' => $this->publisherRegistration,
            'LEGAL_PUBLISHER_ADDRESS' => $this->publisherAddress,
            'LEGAL_PUBLISHER_EMAIL' => $this->publisherEmail,
            'LEGAL_PUBLICATION_DIRECTOR' => $this->publicationDirector,
            'LEGAL_HOST_NAME' => $this->hostName,
            'LEGAL_HOST_ADDRESS' => $this->hostAddress,
        ];

        return array_keys(array_filter($required, fn (string $value) => '' === trim($value)));
    }

    public function complete(): bool
    {
        return [] === $this->missing();
    }

    /**
     * Ce qui manque aux conditions générales de vente : l'identité du vendeur et le médiateur de la consommation.
     * Tant que la liste n'est pas vide, rien ne se vend (l'achat est « bientôt disponible »).
     *
     * @return list<string>
     */
    public function termsMissing(): array
    {
        $required = [
            'LEGAL_PUBLISHER_NAME' => $this->publisherName,
            'LEGAL_PUBLISHER_LEGAL_FORM' => $this->publisherLegalForm,
            'LEGAL_PUBLISHER_REGISTRATION' => $this->publisherRegistration,
            'LEGAL_PUBLISHER_ADDRESS' => $this->publisherAddress,
            'LEGAL_PUBLISHER_EMAIL' => $this->publisherEmail,
            'LEGAL_MEDIATOR_NAME' => $this->mediatorName,
            'LEGAL_MEDIATOR_URL' => $this->mediatorUrl,
        ];

        return array_keys(array_filter($required, fn (string $value) => '' === trim($value)));
    }

    public function canSell(): bool
    {
        return [] === $this->termsMissing();
    }

    /** Durée de la garantie « satisfait ou remboursé », ou null sans garantie. */
    public function refundGuaranteeDays(): ?int
    {
        return ctype_digit(trim($this->refundDays)) && (int) $this->refundDays > 0 ? (int) $this->refundDays : null;
    }
}
