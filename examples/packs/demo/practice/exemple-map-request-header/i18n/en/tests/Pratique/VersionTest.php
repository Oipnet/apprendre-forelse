<?php

namespace App\Tests\Pratique;

use App\Controller\VersionController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestHeader;

class VersionTest extends WebTestCase
{
    public function testTheRequestedVersionIsRead(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/version', server: ['HTTP_X_API_VERSION' => '2']);

        $this->assertResponseIsSuccessful('With the header, the page must respond with a 200.');
        $this->assertSame('Requested version: 2', trim((string) $client->getResponse()->getContent()));
    }

    public function testARequestWithoutTheHeaderIsRejected(): void
    {
        static::createClient()->request('GET', '/api/version');

        $this->assertResponseStatusCodeSame(400, 'Without the X-Api-Version header, the response must be a 400.');
    }

    public function testTheControllerUsesMapRequestHeader(): void
    {
        $parameters = (new \ReflectionMethod(VersionController::class, '__invoke'))->getParameters();

        foreach ($parameters as $parameter) {
            $this->assertNotSame(Request::class, (string) $parameter->getType(), 'The controller must no longer receive the Request object.');
        }
        $mapped = array_filter($parameters, static fn (\ReflectionParameter $p) => $p->getAttributes(MapRequestHeader::class));
        $this->assertCount(1, $mapped, 'One argument must carry #[MapRequestHeader].');
    }
}
