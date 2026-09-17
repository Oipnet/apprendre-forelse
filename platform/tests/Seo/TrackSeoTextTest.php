<?php

namespace App\Tests\Seo;

use App\Content\Chapter;
use App\Content\Track;
use App\Entity\TrackPricing;
use App\Entity\TrackSeo;
use App\Repository\TrackPricingRepository;
use App\Repository\TrackSeoRepository;
use App\Seo\TrackSeoText;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class TrackSeoTextTest extends TestCase
{
    private const string SYMFONY = 'Vous maîtrisez PHP et la POO ? Apprenez Symfony en construisant, chapitre après chapitre, le site de la Taverne du Dragon Ivre.';

    /** @var list<string> */
    private array $warnings = [];

    private function text(?TrackSeo $override = null, ?TrackPricing $pricing = null): TrackSeoText
    {
        $overrides = $this->createStub(TrackSeoRepository::class);
        $overrides->method('findOneByTrack')->willReturn($override);
        $pricings = $this->createStub(TrackPricingRepository::class);
        $pricings->method('findOneByTrack')->willReturn($pricing);
        $logger = new class($this->warnings) extends AbstractLogger {
            /** @param list<string> $warnings */
            public function __construct(private array &$warnings)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->warnings[] = $level.' '.$context['track'];
            }
        };

        return new TrackSeoText($overrides, $pricings, $logger);
    }

    private static function track(string $title = 'Symfony pour les devs PHP', string $description = self::SYMFONY): Track
    {
        return new Track('symfony-pour-dev-php', 'pack', $title, $description, 'symfony-8', [
            new Chapter('c1', 'Premiers pas', ['e1', 'e2']),
            new Chapter('c2', 'La cave', ['e3']),
        ], '/tmp');
    }

    public function testLeTitleSeFaitAvecLePublicDuParcours(): void
    {
        $this->assertSame('Formation Symfony en ligne pour les devs PHP', TrackSeoText::generatedTitle('Symfony pour les devs PHP', 'Symfony'));
        $this->assertSame('Formation Nuxt en ligne pour les devs Vue', TrackSeoText::generatedTitle('Nuxt pour les devs Vue', 'Nuxt'));
        $this->assertSame('Formation Symfony en ligne pour les devs PHP | Forelse', $this->text()->title(self::track(), 'Symfony'));
    }

    public function testSansLeFrameworkDansLeTitreLeTitleSeReplie(): void
    {
        $this->assertSame('Formation Symfony en ligne : Découverte', TrackSeoText::generatedTitle('Découverte', 'Symfony'));
        // Le framework est là, mais la dérivation serait bancale.
        $this->assertSame('Formation Symfony en ligne : Débuter avec Symfony', TrackSeoText::generatedTitle('Débuter avec Symfony', 'Symfony'));
        $this->assertSame('Formation Symfony en ligne : Symfony pour', TrackSeoText::generatedTitle('Symfony pour', 'Symfony'));
        $this->assertSame('Formation Symfony en ligne : Symfony 8', TrackSeoText::generatedTitle('Symfony 8', 'Symfony'));
    }

    public function testLeTitleSaisiDansLAdminPasseAvantLaGeneration(): void
    {
        $override = (new TrackSeo('symfony-pour-dev-php'))->setSeoTitle('Apprendre Symfony en codant');

        $this->assertSame('Apprendre Symfony en codant | Forelse', $this->text($override)->title(self::track(), 'Symfony'));
    }

    public function testUnTitleTropLongEstSignale(): void
    {
        $title = $this->text()->title(self::track('Symfony pour les développeurs PHP qui veulent passer au framework'), 'Symfony');

        $this->assertStringEndsWith('| Forelse', $title, 'Le title reste entier…');
        $this->assertSame(['warning symfony-pour-dev-php'], $this->warnings, '… mais un avertissement est loggué.');
    }

    public function testLaDescriptionDonneLeResumePuisLesChiffres(): void
    {
        $this->assertSame(
            'Un avant-goût de Symfony en deux exercices. 2 chapitres, 3 exercices, premier chapitre gratuit.',
            TrackSeoText::generatedDescription('Un avant-goût de Symfony en deux exercices.', 2, 3, false),
        );
        $this->assertSame('1 chapitre, 1 exercice, premier chapitre gratuit.', TrackSeoText::generatedDescription('', 1, 1, false));
    }

    public function testUnResumeTropLongGardeSesPhrasesEntieres(): void
    {
        $summary = 'Une première phrase qui présente le parcours et son fil rouge, assez longue pour compter vraiment ici. Une seconde phrase qui ne tiendra pas dans la limite.';

        $this->assertSame(
            'Une première phrase qui présente le parcours et son fil rouge, assez longue pour compter vraiment ici. 12 chapitres, 74 exercices, gratuit.',
            TrackSeoText::generatedDescription($summary, 12, 74, true),
        );
    }

    public function testSiLesPhrasesEntieresNeSuffisentPasLaCoupeSeFaitSurUnMot(): void
    {
        $description = TrackSeoText::generatedDescription(self::SYMFONY, 12, 74, false);

        $this->assertSame('Vous maîtrisez PHP et la POO ? Apprenez Symfony en construisant, chapitre après chapitre, le site… 12 chapitres, 74 exercices, premier chapitre gratuit.', $description);
        $this->assertLessThanOrEqual(155, mb_strlen($description));
    }

    public function testGratuitSeulementPourUnTarifEnregistreA0Euro(): void
    {
        $this->assertStringEndsWith(', premier chapitre gratuit.', $this->text()->description(self::track(description: 'Court.')), 'Sans tarif.');
        $this->assertStringEndsWith(', premier chapitre gratuit.', $this->text(pricing: (new TrackPricing('symfony-pour-dev-php'))->setNormalPrice(4900))->description(self::track(description: 'Court.')));
        $this->assertSame('Court. 2 chapitres, 3 exercices, gratuit.', $this->text(pricing: (new TrackPricing('symfony-pour-dev-php'))->setNormalPrice(0))->description(self::track(description: 'Court.')));
    }

    public function testLaDescriptionSaisieDansLAdminPasseAvantLaGeneration(): void
    {
        $override = (new TrackSeo('symfony-pour-dev-php'))->setSeoDescription('  Une description   écrite à la main.  ');

        $this->assertSame('Une description écrite à la main.', $this->text($override)->description(self::track()));
        $this->assertSame('Formation Symfony en ligne pour les devs PHP | Forelse', $this->text($override)->title(self::track(), 'Symfony'), 'Un title vide garde la génération.');
    }
}
