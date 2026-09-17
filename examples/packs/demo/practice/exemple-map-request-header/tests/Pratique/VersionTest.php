<?php

namespace App\Tests\Pratique;

use App\Controller\VersionController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestHeader;

class VersionTest extends WebTestCase
{
    public function testLaVersionDemandeeEstLue(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/version', server: ['HTTP_X_API_VERSION' => '2']);

        $this->assertResponseIsSuccessful('Avec l\'en-tête, la page doit répondre avec un code 200.');
        $this->assertSame('Version demandée : 2', trim((string) $client->getResponse()->getContent()));
    }

    public function testSansEnTeteLaRequeteEstRefusee(): void
    {
        static::createClient()->request('GET', '/api/version');

        $this->assertResponseStatusCodeSame(400, 'Sans l\'en-tête X-Api-Version, la réponse doit être une 400.');
    }

    public function testLeControleurUtiliseMapRequestHeader(): void
    {
        $parameters = (new \ReflectionMethod(VersionController::class, '__invoke'))->getParameters();

        foreach ($parameters as $parameter) {
            $this->assertNotSame(Request::class, (string) $parameter->getType(), 'Le contrôleur ne doit plus recevoir l\'objet Request.');
        }
        $mapped = array_filter($parameters, static fn (\ReflectionParameter $p) => $p->getAttributes(MapRequestHeader::class));
        $this->assertCount(1, $mapped, 'Un argument doit porter #[MapRequestHeader].');
    }
}
