<?php

namespace App\Controller;

use App\Ai\Mentor;
use App\Legal\LegalInfo;
use App\Payment\PaymentGateway;
use App\Seo\SeoWriter;
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
    /** Dernière révision des deux textes : affichée en bas de page et donnée au sitemap. */
    public const string UPDATED_AT = '2026-09-17';

    /**
     * Version des conditions générales de vente : la date de leur dernière révision. Enregistrée avec chaque achat
     * (Purchase::$termsVersion). À changer à chaque modification du texte de legal/terms.html.twig.
     */
    public const string TERMS_VERSION = '2026-09-17';

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
    public function notice(SeoWriter $seo): Response
    {
        $seo->legalNotice();

        return $this->render('legal/notice.html.twig', $this->context());
    }

    #[Route('/confidentialite', name: 'app_privacy', methods: ['GET'])]
    public function privacy(SeoWriter $seo): Response
    {
        $seo->privacy();

        return $this->render('legal/privacy.html.twig', $this->context());
    }

    #[Route('/cgv', name: 'app_terms', methods: ['GET'])]
    public function terms(SeoWriter $seo): Response
    {
        $seo->terms();

        return $this->render('legal/terms.html.twig', [
            ...$this->context(),
            'missing' => $this->legal->termsMissing(),
            'termsVersion' => new \DateTimeImmutable(self::TERMS_VERSION),
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
            'updatedAt' => new \DateTimeImmutable(self::UPDATED_AT),
        ];
    }
}
