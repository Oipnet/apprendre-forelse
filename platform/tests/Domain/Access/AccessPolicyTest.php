<?php

namespace App\Tests\Domain\Access;

use App\Domain\Access\AccessPolicy;
use App\Domain\Access\Learner;
use App\Domain\Access\Track;
use PHPUnit\Framework\TestCase;

/**
 * Pas 1 de la migration du contexte Accès : « cet apprenant peut-il ouvrir ce chapitre ? ».
 *
 * Aujourd'hui, la réponse est dans App\Security\TrackAccessChecker, mêlée à la lecture des faits (dépôts Doctrine,
 * hiérarchie des rôles, horloge). Ici, la règle est pure : on lui donne les faits, elle décide. Aucun Symfony,
 * aucune base. Rassembler les faits sera le travail de la couche Application (un pas suivant).
 *
 * Les règles, reprises de TrackAccessChecker :
 *  1. sans compte, on ne joue aucun chapitre ;
 *  2. le chapitre gratuit d'un parcours est ouvert à tout compte connecté ;
 *  3. un membre de l'équipe (auteur, administrateur) ouvre tout ;
 *  4. un parcours gratuit est ouvert à tout compte ;
 *  5. un chef de cohorte ouvre les parcours que ses cohortes payantes proposent ;
 *  6. sinon, il faut un accès actif à ce parcours (achat, cohorte, offert).
 *
 * Track est le parcours tel que le contexte Accès le voit : un identifiant et « payant ou non ». Ce n'est pas
 * App\Content\Track, qui sait tout du contenu : chaque contexte a son propre modèle.
 */
final class AccessPolicyTest extends TestCase
{
    private AccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AccessPolicy();
    }

    public function testSansCompteAucunChapitreNeSOuvreMemeGratuit(): void
    {
        $this->assertFalse($this->policy->canOpenChapter(Learner::anonymous(), Track::paid('symfony'), freeChapter: true));
        $this->assertFalse($this->policy->canOpenChapter(Learner::anonymous(), Track::free('php'), freeChapter: false));
    }

    public function testLeChapitreGratuitDUnParcoursPayantEstOuvertATousLesComptes(): void
    {
        $this->assertTrue($this->policy->canOpenChapter(Learner::member(42), Track::paid('symfony'), freeChapter: true));
    }

    public function testLesAutresChapitresDUnParcoursPayantSontFermesSansAcces(): void
    {
        $this->assertFalse($this->policy->canOpenChapter(Learner::member(42), Track::paid('symfony'), freeChapter: false));
    }

    public function testUnMembreDeLEquipeOuvreTout(): void
    {
        $this->assertTrue($this->policy->canOpenChapter(Learner::staff(1), Track::paid('symfony'), freeChapter: false));
    }

    public function testUnParcoursGratuitEstOuvertATousLesComptes(): void
    {
        $this->assertTrue($this->policy->canOpenChapter(Learner::member(42), Track::free('php'), freeChapter: false));
    }

    public function testUnAccesActifOuvreSonParcoursEtPasLesAutres(): void
    {
        $learner = Learner::member(42)->withActiveAccessTo('symfony');

        $this->assertTrue($this->policy->canOpenChapter($learner, Track::paid('symfony'), freeChapter: false));
        $this->assertFalse($this->policy->canOpenChapter($learner, Track::paid('laravel'), freeChapter: false));
    }

    public function testUnChefDeCohorteOuvreLesParcoursQueSesCohortesProposent(): void
    {
        $chef = Learner::member(7)->offeringThroughCohorts('symfony');

        $this->assertTrue($this->policy->canOpenChapter($chef, Track::paid('symfony'), freeChapter: false));
        $this->assertFalse($this->policy->canOpenChapter($chef, Track::paid('laravel'), freeChapter: false));
    }

    public function testLesFaitsSAccumulentSansSeRemplacer(): void
    {
        // Learner est immuable : chaque with… renvoie un nouvel apprenant, l'original ne change pas.
        $member = Learner::member(42);
        $learner = $member->withActiveAccessTo('symfony')->withActiveAccessTo('laravel');

        $this->assertTrue($this->policy->hasFullAccess($learner, Track::paid('symfony')));
        $this->assertTrue($this->policy->hasFullAccess($learner, Track::paid('laravel')));
        $this->assertFalse($this->policy->hasFullAccess($member, Track::paid('symfony')));
    }

    public function testLAccesCompletIgnoreLeChapitreGratuit(): void
    {
        // « Continuer » ou « Acheter » sur la page du parcours : le chapitre gratuit ne suffit pas à tout ouvrir.
        $this->assertFalse($this->policy->hasFullAccess(Learner::member(42), Track::paid('symfony')));
        $this->assertFalse($this->policy->hasFullAccess(Learner::anonymous(), Track::free('php')));
        $this->assertTrue($this->policy->hasFullAccess(Learner::member(42), Track::free('php')));
    }
}
