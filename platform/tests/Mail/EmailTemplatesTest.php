<?php

namespace App\Tests\Mail;

use App\Content\ContentRepository;
use App\Entity\PriceKind;
use App\Entity\Purchase;
use App\Entity\User;
use App\Legal\LegalInfo;
use App\Legal\LegalVersions;
use App\Payment\WithdrawalWaiver;
use App\Tests\ThemeTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mime\BodyRendererInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use Twig\Environment;

/**
 * Le rendu de chaque gabarit d'email, dans chacune de ses variantes : les versions HTML et texte portent les mêmes
 * liens, le texte n'a pas de lignes vides en trop, le HTML a sa langue et son pied de page, et une marque blanche
 * n'y laisse rien du moteur.
 */
final class EmailTemplatesTest extends KernelTestCase
{
    use ThemeTrait;

    private ?string $themeDir = null;

    protected function tearDown(): void
    {
        if (null !== $this->themeDir) {
            (new Filesystem())->remove($this->themeDir);
        }
        parent::tearDown();
    }

    /** @return array{html: string, text: string} */
    private function render(string $template, array $context, string $subject = 'Objet'): array
    {
        $email = (new TemplatedEmail())->subject($subject)
            ->htmlTemplate("emails/{$template}.html.twig")
            ->textTemplate("emails/{$template}.txt.twig")
            ->context($context);
        static::getContainer()->get(BodyRendererInterface::class)->render($email);

        return ['html' => (string) $email->getHtmlBody(), 'text' => (string) $email->getTextBody()];
    }

    private static function user(): User
    {
        return (new User())->setEmail('ada@example.test')->setDisplayName('Ada');
    }

    private function purchase(bool $receipt, bool $invoice, bool $terms): Purchase
    {
        $purchase = new Purchase(self::user(), 'decouverte', 4900, PriceKind::Normal, WithdrawalWaiver::TEXT, new \DateTimeImmutable('2026-09-01 10:00'), termsVersion: $terms ? LegalVersions::TERMS_VERSION : null);
        $purchase->markPaid(new \DateTimeImmutable('2026-09-01 10:05'), 4900, 'pi_1');
        $purchase->setReceiptUrl($receipt ? 'https://pay.stripe.test/receipts/pi_1' : null);
        $purchase->attachInvoice($invoice ? 'in_1' : null);

        return $purchase;
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function variantes(): iterable
    {
        foreach ([false, true] as $change) {
            yield 'confirmation d\'adresse, change='.var_export($change, true) => ['confirm_email', ['user' => self::user(), 'link' => 'https://localhost/compte/confirmer?signature=abc&expires=1', 'change' => $change, 'hours' => 48]];
        }
        foreach (['contact@example.test', ''] as $contact) {
            yield 'adresse changée, contact='.($contact ?: 'vide') => ['email_changed', ['user' => self::user(), 'newEmail' => 'nouvelle@example.test', 'contact' => $contact]];
        }
        yield 'tentative d\'inscription' => ['registration_attempt', ['user' => self::user()]];
        yield 'mot de passe' => ['reset_password', ['user' => self::user(), 'resetToken' => new ResetPasswordToken('jeton', new \DateTimeImmutable('+1 hour'), time())]];
        foreach ([[true, true], [true, false], [false, true], [false, false]] as [$recu, $facture]) {
            foreach ([true, false] as $cgv) {
                foreach ([true, false] as $parcours) {
                    yield sprintf('achat, reçu=%d facture=%d cgv=%d parcours=%d', $recu, $facture, $cgv, $parcours) => ['purchase_confirmation', ['recu' => $recu, 'facture' => $facture, 'cgv' => $cgv, 'parcours' => $parcours]];
                }
            }
        }
    }

    /** @param array<string, mixed> $context */
    #[DataProvider('variantes')]
    public function testChaqueGabaritSeRendProprement(string $template, array $context): void
    {
        self::bootKernel();
        if ('purchase_confirmation' === $template) {
            $context = [
                'purchase' => $this->purchase($context['recu'], $context['facture'], $context['cgv']),
                'track' => $context['parcours'] ? static::getContainer()->get(ContentRepository::class)->findTrack('decouverte') : null,
                'seller' => new LegalInfo('Forelse SAS', 'SAS au capital de 1 000 €', 'RCS Lyon 123 456 789', publisherAddress: '1 rue du Port, 69000 Lyon'),
            ];
        }
        ['html' => $html, 'text' => $text] = $this->render($template, $context);

        // Les mêmes liens des deux côtés : pas de lien affiché différent du lien suivi, pas d'ancre perdue.
        $this->assertSame($this->liensHtml($html), $this->liensTexte($text), 'Liens HTML et texte');
        $this->assertStringNotContainsString("\n\n\n", $text, 'Pas deux lignes vides d\'affilée.');
        $this->assertStringStartsNotWith("\n", $text);

        $this->assertStringContainsString('<html lang="fr">', $html);
        $this->assertStringContainsString('Vous recevez cet email parce qu', $html, 'Le pied dit pourquoi.');
        $this->assertStringContainsString('Vous recevez cet email parce qu', $text);
        $this->assertStringContainsString('Forelse · apprendre', $html, 'La signature de la marque.');
        $this->assertStringContainsString('http://localhost/img/logo.png', $html, 'Le logo PNG, en adresse absolue.');
        $this->assertMatchesRegularExpression('/display:none[^>]*>[^<]+</', $html, 'Un texte d\'aperçu.');
    }

    public function testLaConfirmationDAchatDitLeVendeurEtNeDoublePasLesLiens(): void
    {
        self::bootKernel();
        $render = fn (bool $recu, bool $facture, LegalInfo $vendeur) => $this->render('purchase_confirmation', ['purchase' => $this->purchase($recu, $facture, true), 'track' => null, 'seller' => $vendeur]);

        ['html' => $html, 'text' => $text] = $render(false, true, new LegalInfo('Forelse SAS', publisherAddress: '1 rue du Port, 69000 Lyon'));
        $this->assertSame(1, substr_count($text, 'http://localhost/compte#achats'), 'Sans reçu, reçu et facture tiennent en une ligne.');
        $this->assertStringContainsString('Vendeur : Forelse SAS, 1 rue du Port, 69000 Lyon.', $text);
        $this->assertStringContainsString('Vendeur : Forelse SAS, 1 rue du Port, 69000 Lyon.', $html);
        $this->assertStringContainsString('Montant payé : 49,00', $text);
        $this->assertStringContainsString(', le 01/09/2026.', $text);

        ['text' => $text] = $render(true, true, new LegalInfo());
        $this->assertStringContainsString('Télécharger la facture (depuis votre compte) : http://localhost/compte#achats', $text);
        $this->assertStringNotContainsString('Vendeur', $text, 'Mentions légales vides : pas de ligne vide de sens.');
    }

    /** Une marque blanche : son nom et sa signature partout, rien du moteur ; sans logo PNG, le nom seul. */
    public function testUneMarqueBlancheNeLaisseRienDuMoteur(): void
    {
        self::bootKernel();
        $this->themeDir = sys_get_temp_dir().'/theme-'.bin2hex(random_bytes(6));
        (new Filesystem())->dumpFile($this->themeDir.'/theme.yaml', "name: Atelier Bigorneau\nchip: coder\nlogo: logo.webp\n");
        (new Filesystem())->dumpFile($this->themeDir.'/logo.webp', 'RIFF');
        static::getContainer()->get(Environment::class)->addGlobal('theme', self::theme($this->themeDir));

        ['html' => $html, 'text' => $text] = $this->render('registration_attempt', ['user' => self::user()]);

        foreach (['html' => $html, 'text' => $text] as $version => $corps) {
            $this->assertStringContainsString('Atelier Bigorneau', $corps, $version);
            $this->assertStringNotContainsString('Forelse', $corps, $version);
        }
        $this->assertStringNotContainsString('<img', $html, 'Un logo webp passe mal dans les clients mail : le nom seul.');
    }

    /**
     * Le bouton et les liens d'un email prennent l'accent du thème ; le texte du bouton reste lisible, blanc sur un
     * accent sombre, à l'encre sur un accent clair. Sans couleur déclarée, ce sont celles du thème default.
     */
    #[DataProvider('accents')]
    public function testLeBoutonPrendLAccentDuTheme(?string $accent, string $fond, string $texte): void
    {
        self::bootKernel();
        $this->themeDir = sys_get_temp_dir().'/theme-'.bin2hex(random_bytes(6));
        (new Filesystem())->dumpFile($this->themeDir.'/theme.yaml', "name: Atelier Bigorneau\n".($accent ? "colors:\n  accent: '{$accent}'\n  ink: '#101010'\n" : ''));
        static::getContainer()->get(Environment::class)->addGlobal('theme', self::theme($this->themeDir));

        ['html' => $html] = $this->render('reset_password', ['user' => self::user(), 'resetToken' => new ResetPasswordToken('jeton', new \DateTimeImmutable('+1 hour'), time())]);

        $this->assertStringContainsString('class="bouton" style="border-radius:8px;background:'.$fond.';"', $html);
        $this->assertMatchesRegularExpression('#<td class="bouton"[^>]*>\s*<a [^>]*color:'.preg_quote($texte, '#').';#', $html);
        $this->assertStringContainsString('class="lien" href="http://localhost/" style="color:'.$fond.';"', $html);
    }

    /** @return iterable<string, array{string|null, string, string}> */
    public static function accents(): iterable
    {
        yield 'sans couleur : le thème default' => [null, '#1d4ed8', '#ffffff'];
        yield 'accent sombre : texte blanc' => ['#9a3412', '#9a3412', '#ffffff'];
        yield 'accent clair : texte à l\'encre du thème' => ['#fde68a', '#fde68a', '#101010'];
    }

    /** @return list<string> */
    private function liensHtml(string $html): array
    {
        preg_match_all('/href="([^"]+)"/', $html, $m);
        $liens = array_filter(array_map('html_entity_decode', $m[1]), static fn (string $l) => !str_starts_with($l, 'mailto:'));

        return self::uniques($liens);
    }

    /** @return list<string> */
    private function liensTexte(string $text): array
    {
        preg_match_all('#https?://[^\s)]+#', $text, $m);

        return self::uniques(array_map(static fn (string $l) => rtrim($l, '.,;'), $m[0]));
    }

    /**
     * @param iterable<string> $liens
     *
     * @return list<string>
     */
    private static function uniques(iterable $liens): array
    {
        $liens = array_values(array_unique([...$liens]));
        sort($liens);

        return $liens;
    }
}
