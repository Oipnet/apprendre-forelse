<?php

namespace App\Tests\Command;

use App\Content\Check\LinkChecker;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ContentLinksTest extends KernelTestCase
{
    public function testLesLiensDesFichesDeCoursSontVerifiesAvecCeuxDesExercices(): void
    {
        self::bootKernel();
        // Les pages répondent, mais l'ancre « #route-parameters » a disparu.
        $client = new MockHttpClient(static fn () => new MockResponse('<h2 id="autre-section">…</h2>'));
        static::getContainer()->set(LinkChecker::class, new LinkChecker($client));

        $tester = new CommandTester((new Application(self::$kernel))->find('content:links'));
        $status = $tester->execute(['target' => 'demo']);
        $display = $tester->getDisplay(true);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('✔ https://symfony.com/doc/current/page_creation.html', $display);
        $this->assertStringContainsString('✘ https://symfony.com/doc/current/routing.html#route-parameters', $display);
        $this->assertMatchesRegularExpression(
            '~utilisé par : .*decouverte/02-bonjour-prenom.*decouverte/chapters/bonjour/lesson\.md~',
            $display,
            'Un lien cassé cite l\'exercice et la fiche de cours qui l\'utilisent.',
        );
    }
}
