<?php

namespace App\Tests\Entity;

use App\Entity\AccessSource;
use App\Entity\ContactSubject;
use App\Entity\FeedbackKind;
use App\Entity\FundingMode;
use App\Entity\PriceKind;
use App\Entity\ProgressStatus;
use App\Entity\PurchaseStatus;
use PHPUnit\Framework\TestCase;

/** Les listes de l'administration viennent des enums : chaque cas a son libellé et, s'il s'affiche en badge, sa couleur. */
final class EnumLabelsTest extends TestCase
{
    public function testChaqueCasAUnBadge(): void
    {
        foreach ([PurchaseStatus::class, FeedbackKind::class, ContactSubject::class, ProgressStatus::class, AccessSource::class, FundingMode::class] as $enum) {
            $this->assertSame(array_column($enum::cases(), 'name'), array_keys($enum::badges()), $enum);
        }
        $this->assertSame('danger', PurchaseStatus::badges()['Disputed'], 'Un achat contesté se voit.');
    }

    public function testLesChoixSuiventLesCas(): void
    {
        $this->assertSame(['Normal' => 'normal', 'Fondateur' => 'founder', 'Cohorte' => 'cohort'], PriceKind::valueChoices());
        $this->assertSame(['Établissement' => FundingMode::Institution, 'Apprenants' => FundingMode::Learners], FundingMode::choices());
    }
}
