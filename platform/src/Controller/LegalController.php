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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Mentions légales et politique de confidentialité. Les textes décrivent ce que le moteur fait
 * réellement ; ce qui dépend de l'instance (éditeur, hébergeur, IA activée, bêta fermée) est injecté.
 *
 * Le texte juridique s'écrit d'un bloc, pas en clés de traduction : chaque langue a ses gabarits (legal/en/…), et la
 * version anglaise renvoie à la française, qui fait foi.
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

    #[Route(['fr' => '/mentions-legales', 'en' => '/en/legal-notice'], name: 'app_legal_notice', methods: ['GET'])]
    #[Seo('mentions.titre', 'mentions.description', domain: 'legal')]
    public function notice(Request $request): Response
    {
        return $this->render($this->template($request, 'notice'), $this->context());
    }

    #[Route(['fr' => '/confidentialite', 'en' => '/en/privacy'], name: 'app_privacy', methods: ['GET'])]
    #[Seo('confidentialite.titre', 'confidentialite.description', domain: 'legal')]
    public function privacy(Request $request): Response
    {
        return $this->render($this->template($request, 'privacy'), $this->context());
    }

    #[Route(['fr' => '/cgv', 'en' => '/en/terms'], name: 'app_terms', methods: ['GET'])]
    #[Seo('cgv.titre', 'cgv.description', domain: 'legal')]
    public function terms(Request $request): Response
    {
        return $this->render($this->template($request, 'terms'), [
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

    /** legal/<page>.html.twig en français, legal/<langue>/<page>.html.twig sinon. */
    private function template(Request $request, string $page): string
    {
        $locale = $request->getLocale();

        return sprintf('legal/%s%s.html.twig', $locale === $request->getDefaultLocale() ? '' : $locale.'/', $page);
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
