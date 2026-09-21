<?php

namespace App\Tests\Security;

use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Entity\Cohort;
use App\Entity\User;
use App\Repository\CohortRepository;
use App\Repository\TrackAccessRepository;
use App\Repository\TrackPricingRepository;
use App\Security\TrackAccessChecker;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

/** Pack de test « payant » : chapitre gratuit (e1), puis « complet » (e2, e3) ; « cohortes » a un parcours en préparation. */
final class TrackAccessCheckerTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    private ContentRepository $content;

    protected function setUp(): void
    {
        $this->content = new ContentRepository(
            [__DIR__.'/../Fixtures/packs/payant', __DIR__.'/../Fixtures/packs/cohortes'],
            new EnvironmentRegistry(self::ROOT.'/environments'),
            new Version(self::ROOT.'/VERSION'),
        );
    }

    /**
     * @param list<string> $activeTrackIds ce que renvoie la base : accès actifs (les expirés et révoqués n'y sont pas)
     * @param list<Cohort> $chefCohorts
     */
    private function checker(bool $paid = true, array $activeTrackIds = [], array $chefCohorts = []): TrackAccessChecker
    {
        $accesses = $this->createStub(TrackAccessRepository::class);
        $accesses->method('findActiveTrackIds')->willReturn($activeTrackIds);
        $pricings = $this->createStub(TrackPricingRepository::class);
        $pricings->method('isPaid')->willReturn($paid);
        $cohorts = $this->createStub(CohortRepository::class);
        $cohorts->method('findByChef')->willReturn($chefCohorts);

        return new TrackAccessChecker($accesses, $pricings, $cohorts, $this->content, new RoleHierarchy([User::ROLE_ADMIN => [User::ROLE_AUTEUR, User::ROLE_CHEF_COHORTE]]), new MockClock());
    }

    private static function user(string ...$roles): User
    {
        $user = (new User())->setEmail('ada@example.test')->setDisplayName('Ada')->setRoles($roles);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 42);

        return $user;
    }

    /** @return array{\App\Content\Track, \App\Content\Chapter, \App\Content\Chapter} */
    private function payant(): array
    {
        $track = $this->content->findTrack('payant');

        return [$track, $track->chapters[0], $track->chapters[1]];
    }

    public function testLePremierChapitreEstGratuitMaisDemandeUnCompte(): void
    {
        [$track, $libre, $complet] = $this->payant();
        $checker = $this->checker();

        $this->assertTrue($checker->canAccess(self::user(), $track, $libre));
        $this->assertFalse($checker->canAccess(null, $track, $libre), 'Gratuit ne veut pas dire sans compte.');
        $this->assertFalse($checker->canAccess(null, $track, $complet));
        $this->assertFalse($checker->canAccess(self::user(), $track, $complet), 'Parcours payant, aucun accès.');
        $this->assertTrue($checker->canAccessExercise(self::user(), $this->content->findExercise('payant', 'e1')));
        $this->assertFalse($checker->canAccessExercise(null, $this->content->findExercise('payant', 'e1')));
        $this->assertFalse($checker->canAccessExercise(self::user(), $this->content->findExercise('payant', 'e3')));
    }

    public function testUnExerciceDuPremierChapitreResteMarqueGratuit(): void
    {
        $checker = $this->checker();

        $this->assertTrue($checker->isFreeExercise($this->content->findExercise('payant', 'e1')));
        $this->assertFalse($checker->isFreeExercise($this->content->findExercise('payant', 'e3')));
    }

    public function testLePremierChapitreDUnParcoursEnPreparationNEstPasLibre(): void
    {
        $track = $this->content->findTrack('atelier-secret');

        $this->assertFalse($this->checker()->canAccess(null, $track, $track->chapters[0]));
        $this->assertFalse($this->checker()->canAccess(self::user(), $track, $track->chapters[0]), 'Même avec un compte.');
    }

    public function testUnAccesActifOuvreLeParcours(): void
    {
        [$track, , $complet] = $this->payant();

        $this->assertTrue($this->checker(activeTrackIds: ['payant'])->canAccess(self::user(), $track, $complet));
        $this->assertFalse($this->checker(activeTrackIds: ['symfony-bases'])->canAccess(self::user(), $track, $complet), 'L\'accès vaut pour son parcours seulement.');
    }

    public function testUnAccesExpireOuRevoqueNOuvrePlusRien(): void
    {
        [$track, , $complet] = $this->payant();
        // Le dépôt ne renvoie que les accès actifs : un accès expiré ou révoqué n'y figure pas (voir TrackAccessTest).
        $this->assertFalse($this->checker(activeTrackIds: [])->canAccess(self::user(), $track, $complet));
    }

    public function testUnAdministrateurEtUnAuteurAccedentATout(): void
    {
        [$track, , $complet] = $this->payant();

        $this->assertTrue($this->checker()->canAccess(self::user(User::ROLE_ADMIN), $track, $complet));
        $this->assertTrue($this->checker()->canAccess(self::user(User::ROLE_AUTEUR), $track, $complet));
    }

    public function testUnParcoursGratuitEstOuvertATousLesComptes(): void
    {
        [$track, , $complet] = $this->payant();

        $this->assertTrue($this->checker(paid: false)->canAccess(self::user(), $track, $complet));
        $this->assertFalse($this->checker(paid: false)->canAccess(null, $track, $complet), 'Mais pas sans compte.');
    }

    public function testUnChefAccedeAuxParcoursDeSesCohortes(): void
    {
        [$track, , $complet] = $this->payant();
        $sienne = (new Cohort())->setName('iut')->setCode('iut')->setAvailableTrackIds(['payant']);
        $autre = (new Cohort())->setName('autre')->setCode('autre')->setAvailableTrackIds(['symfony-bases']);

        $this->assertTrue($this->checker(chefCohorts: [$sienne])->canAccess(self::user(User::ROLE_CHEF_COHORTE), $track, $complet));
        $this->assertFalse($this->checker(chefCohorts: [$autre])->canAccess(self::user(User::ROLE_CHEF_COHORTE), $track, $complet));
        $this->assertFalse($this->checker(chefCohorts: [$sienne])->canAccess(self::user(), $track, $complet), 'Sans le rôle, la cohorte ne compte pas.');
    }
}
