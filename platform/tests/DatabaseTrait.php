<?php

namespace App\Tests;

use App\Entity\Cohort;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Base de test (PostgreSQL « formation_test ») dont le schéma est recréé à chaque test. */
trait DatabaseTrait
{
    protected function resetDatabase(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        return $entityManager;
    }

    protected function createUser(string $email = 'ada@example.test', string $displayName = 'Ada', ?string $cohort = null): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail($email)->setDisplayName($displayName)->setPassword('non-utilisé')->setCohort(null === $cohort ? null : $this->createCohort($cohort));
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    /** La cohorte « code » (nom = code), créée si elle n'existe pas encore. */
    protected function createCohort(string $code, bool $active = true): Cohort
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $cohort = $entityManager->getRepository(Cohort::class)->findOneBy(['code' => $code]);
        if (!$cohort) {
            $cohort = (new Cohort())->setName($code)->setCode($code)->setActive($active);
            $entityManager->persist($cohort);
            $entityManager->flush();
        }

        return $cohort;
    }

    /** @param array<mixed> $body */
    protected function json(KernelBrowser $client, string $method, string $uri, array $body = []): mixed
    {
        $client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body));
        $content = $client->getResponse()->getContent();

        return '' === $content ? null : json_decode($content, true);
    }
}
