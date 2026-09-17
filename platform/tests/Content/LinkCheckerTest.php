<?php

namespace App\Tests\Content;

use App\Content\Check\LinkChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LinkCheckerTest extends TestCase
{
    public function testVerifieLaPageEtLAncre(): void
    {
        $pages = [
            'https://doc.test/routing.html' => new MockResponse('<h2 id="route-parameters">Route Parameters</h2>'),
            'https://doc.test/disparue.html' => new MockResponse('Not found', ['http_code' => 404]),
        ];
        $requested = [];
        $client = new MockHttpClient(static function (string $method, string $url) use ($pages, &$requested) {
            $requested[] = $url;

            return $pages[$url];
        });

        $results = (new LinkChecker($client))->check([
            'https://doc.test/routing.html',
            'https://doc.test/routing.html#route-parameters',
            'https://doc.test/routing.html#section-renommee',
            'https://doc.test/disparue.html',
        ]);

        $this->assertSame([
            'https://doc.test/routing.html' => null,
            'https://doc.test/routing.html#route-parameters' => null,
            'https://doc.test/routing.html#section-renommee' => 'ancre « #section-renommee » introuvable dans la page',
            'https://doc.test/disparue.html' => 'HTTP 404',
        ], $results);
        $this->assertSame(['https://doc.test/routing.html', 'https://doc.test/disparue.html'], $requested, 'Une seule requête par page, quelle que soit l\'ancre.');
    }

    public function testUneAncreSansGuillemetsDansUnHtmlMinifie(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse('<h3 id=env_file>env_file</h3><h3 id=env class=anchor>--env</h3><h3 id=rm>--rm</h3>'));

        $this->assertSame([
            'https://docs.test/run/#env' => null,
            'https://docs.test/run/#rm' => null,
            'https://docs.test/run/#en' => 'ancre « #en » introuvable dans la page',
        ], (new LinkChecker($client))->check(['https://docs.test/run/#env', 'https://docs.test/run/#rm', 'https://docs.test/run/#en']), 'id=env sans guillemets est une ancre ; id=env_file ne vaut pas #en ni #env.');
    }
}
