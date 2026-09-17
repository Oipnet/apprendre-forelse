<?php

namespace App\Tests\Twig;

use App\Twig\DurationExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DurationExtensionTest extends TestCase
{
    #[DataProvider('durees')]
    public function testUneDureeSArronditACeQuUneEstimationPeutPromettre(?int $minutes, string $attendu): void
    {
        $this->assertSame($attendu, DurationExtension::format($minutes));
    }

    public static function durees(): iterable
    {
        yield 'rien' => [null, ''];
        yield 'moins d\'une heure' => [20, '20 min'];
        yield 'une heure pile' => [60, '1 h'];
        yield 'au quart d\'heure près' => [200, '3 h 15'];
        yield 'arrondi au quart d\'heure supérieur' => [133, '2 h 15'];
        yield 'dix heures et plus : à l\'heure près' => [2785, '46 h'];
    }

    public function testFormatIsoPourSchemaOrg(): void
    {
        $this->assertSame('PT46H25M', DurationExtension::iso(2785));
        $this->assertSame('PT2H', DurationExtension::iso(120));
        $this->assertSame('PT45M', DurationExtension::iso(45));
    }
}
