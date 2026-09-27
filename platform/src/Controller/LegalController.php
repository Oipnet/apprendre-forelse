<?php

namespace App\Controller;

use App\Ai\Mentor;
use App\Legal\LegalInfo;
use App\Legal\LegalVersions;
use App\Payment\PaymentGateway;
use App\Seo\Seo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Mentions légales et politique de confidentialité. Les textes décrivent ce que le moteur fait
 * réellement ; ce qui dépend de l'instance (éditeur, hébergeur, IA activée, bêta fermée) est injecté.
 */
final class LegalController extends AbstractController
{
    public function __construct(
        private readonly LegalInfo $legal,
        private readonly Mentor $mentor,
        private readonly PaymentGateway $payments,
        #[Autowire(env: 'bool:REGISTRATION_INVITE_ONLY')]
        private readonly bool $inviteOnly,
        #[Autowire(env: 'DEFAULT_URI')]
        private readonly string $siteUrl,
        #[Autowire(env: 'SANDBOX_ORIGIN')]
        private readonly string $sandboxOrigin,
    ) {
    }

    #[Route('/mentions-legales', name: 'app_legal_notice', methods: ['GET'])]
    #[Seo('Mentions légales', 'Mentions légales du site : éditeur, directeur de la publication, hébergeur de la plateforme et du bac à sable où s\'exécute le code des apprenants.')]
    public function notice(): Response
    {
        return $this->render('legal/notice.html.twig', $this->context());
    }

    #[Route('/confidentialite', name: 'app_privacy', methods: ['GET'])]
    #[Seo('Politique de confidentialité', 'Politique de confidentialité : données collectées, finalités, durées de conservation, sous-traitants et exercice de vos droits sur vos données.')]
    public function privacy(): Response
    {
        return $this->render('legal/privacy.html.twig', $this->context());
    }

    #[Route('/cgv', name: 'app_terms', methods: ['GET'])]
    #[Seo('Conditions générales de vente', 'Conditions générales de vente des parcours : prix TTC, commande et paiement, accès aux contenus, droit de rétractation, garanties et médiation.')]
    public function terms(): Response
    {
        return $this->render('legal/terms.html.twig', [
            ...$this->context(),
            'missing' => $this->legal->termsMissing(),
            'termsVersion' => new \DateTimeImmutable(LegalVersions::TERMS_VERSION),
        ]);
    }

    /**
     * Où signaler une faille (RFC 9116). L'adresse est celle de l'éditeur : sans elle, pas de fichier.
     * L'expiration, obligatoire, avance d'elle-même : un an après le début du mois, stable d'une requête à l'autre.
     */
    #[Route('/.well-known/security.txt', name: 'app_security_txt', methods: ['GET'], format: 'txt')]
    public function securityTxt(ClockInterface $clock): Response
    {
        $email = trim($this->legal->publisherEmail);
        if ('' === $email) {
            throw $this->createNotFoundException('Aucune adresse de contact (LEGAL_PUBLISHER_EMAIL).');
        }
        $expires = $clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify('first day of this month midnight')->modify('+1 year');
        $lines = [
            'Contact: mailto:'.$email,
            'Expires: '.$expires->format('Y-m-d\TH:i:s\Z'),
            'Preferred-Languages: fr, en',
            'Canonical: '.$this->generateUrl('app_security_txt', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'Policy: '.$this->generateUrl('app_legal_notice', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];

        return (new Response(implode("\n", $lines)."\n", headers: ['Content-Type' => 'text/plain; charset=UTF-8']))->setPublic()->setMaxAge(86400);
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'legal' => $this->legal,
            'missing' => $this->legal->missing(),
            'aiEnabled' => $this->mentor->disponible(),
            // Parcours payants : les achats et Stripe n'apparaissent que si le paiement est configuré.
            'paymentsEnabled' => $this->payments->isConfigured(),
            'inviteOnly' => $this->inviteOnly,
            'siteHost' => parse_url($this->siteUrl, \PHP_URL_HOST) ?: $this->siteUrl,
            'sandboxHost' => parse_url($this->sandboxOrigin, \PHP_URL_HOST) ?: $this->sandboxOrigin,
            'updatedAt' => new \DateTimeImmutable(LegalVersions::UPDATED_AT),
        ];
    }
}
