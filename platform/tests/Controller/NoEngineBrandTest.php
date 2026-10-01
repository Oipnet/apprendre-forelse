<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Instance\SelfHostingPage;
use App\Tests\DatabaseTrait;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\BodyRendererInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;

/**
 * Le moteur ne livre aucune marque (#231) : sans thème installé, il s'appelle « default », et rien de Forelse — son
 * nom, son fil rouge, son auteur — n'apparaît sur une page du sitemap, la page d'exercice, l'atelier ou un email.
 * Seule l'adresse du dépôt du moteur, que l'AGPL impose de donner, contient encore le mot.
 */
final class NoEngineBrandTest extends WebTestCase
{
    use DatabaseTrait;

    private const string BRAND = '/forelse|dragon ivre|taverne|arnaud/i';

    public function testAucunePageNiAucunEmailNeParleDeForelse(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->resetDatabase();

        $client->request('GET', '/');
        $this->assertSelectorTextSame('.site-header .site-name', 'default');

        $client->request('GET', '/sitemap.xml');
        preg_match_all('#<loc>([^<]+)</loc>#', (string) $client->getResponse()->getContent(), $locations);
        $pages = [...array_map(static fn (string $url): string => (string) parse_url($url, \PHP_URL_PATH), $locations[1]), '/connexion', '/inscription', '/mot-de-passe-oublie'];
        $this->assertBrandFree($client, $pages);

        $client->request('GET', '/auto-hebergement');
        $this->assertResponseStatusCodeSame(404, 'Sans thème qui la rédige, pas de page « Auto-hébergement ».');

        // La page d'exercice et l'atelier, avec un compte d'auteur.
        $client->loginUser($this->createUser()->setRoles([User::ROLE_AUTEUR]));
        static::getContainer()->get('doctrine')->getManager()->flush();
        $this->assertBrandFree($client, ['/parcours/decouverte/01-bonjour', '/atelier', '/atelier/decouverte', '/atelier/decouverte/01-bonjour', '/compte']);

        foreach (['reset_password' => ['resetToken' => new ResetPasswordToken('jeton', new \DateTimeImmutable('+1 hour'), time())], 'registration_attempt' => []] as $template => $context) {
            $email = (new TemplatedEmail())->subject('Objet')
                ->htmlTemplate("emails/{$template}.html.twig")
                ->textTemplate("emails/{$template}.txt.twig")
                ->context(['user' => (new User())->setEmail('ada@example.test')->setDisplayName('Ada')] + $context);
            static::getContainer()->get(BodyRendererInterface::class)->render($email);
            $this->assertDoesNotMatchRegularExpression(self::BRAND, (string) $email->getHtmlBody(), $template);
            $this->assertDoesNotMatchRegularExpression(self::BRAND, (string) $email->getTextBody(), $template);
        }
    }

    /** @param list<string> $urls */
    private function assertBrandFree(KernelBrowser $client, array $urls): void
    {
        foreach ($urls as $url) {
            $client->request('GET', $url);
            $this->assertSame(200, $client->getResponse()->getStatusCode(), $url);
            $html = str_replace(SelfHostingPage::REPOSITORY, '', (string) $client->getResponse()->getContent());
            $this->assertDoesNotMatchRegularExpression(self::BRAND, $html, $url);
        }
    }
}
