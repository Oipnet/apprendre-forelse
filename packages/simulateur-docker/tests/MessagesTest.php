<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Tests;

use Forelse\DockerSim\Engine\Docker;
use Forelse\DockerSim\Http\HttpResponse;
use Forelse\DockerSim\Messages;
use PHPUnit\Framework\TestCase;

/** Le catalogue des textes montrés à l'apprenant (palier 1 de l'internationalisation : le français seul). */
final class MessagesTest extends TestCase
{
    protected function tearDown(): void
    {
        Messages::choisirLangue(Messages::LANGUE_PAR_DEFAUT);
    }

    public function testInsereLesParametres(): void
    {
        $this->assertSame('Aucune image « php:8.4 ».', Messages::texte('test.no_image', ['php:8.4']));
        $this->assertSame('Le build aurait dû échouer.', Messages::texte('test.build_should_fail'));
    }

    public function testSeReplieSurLeFrancais(): void
    {
        foreach (['fr', 'fr-FR', 'FR', '', 'en', 'en-GB', 'toString'] as $langue) {
            $this->assertSame(Messages::catalogue('fr'), Messages::catalogue($langue), $langue);
            $this->assertSame('Ce site est inaccessible', Messages::texte('browser.unreachable', [], $langue));
        }
        Messages::choisirLangue('en');
        $this->assertSame('fr', Messages::langue());
    }

    public function testUneCleInconnueEstUneErreur(): void
    {
        $this->expectException(\LogicException::class);
        Messages::texte('cle.inconnue');
    }

    /** Chaque clé nommée dans src/ existe, et chaque clé du catalogue sert. */
    public function testLesClesDuCodeExistent(): void
    {
        $utilisees = [];
        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__).'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($fichiers as $fichier) {
            preg_match_all("/Messages::texte\\('([\\w.]+)'/", (string) file_get_contents((string) $fichier), $trouvees);
            array_push($utilisees, ...$trouvees[1]);
        }
        $catalogue = array_keys(Messages::catalogue());
        $this->assertSame([], array_values(array_diff(array_unique($utilisees), $catalogue)), 'clés absentes du catalogue');
        $this->assertSame([], array_values(array_diff($catalogue, $utilisees)), 'clés inutilisées');
    }

    public function testLaPageDErreurDuNavigateurEstDansLaLangueDesMessages(): void
    {
        $page = Docker::browserError(HttpResponse::failure('refused', 'Rien ici.'), 8080);
        $this->assertStringContainsString('<html lang="fr">', $page);
        $this->assertStringContainsString('<strong>localhost</strong> n\'autorise pas la connexion.', $page);
        $this->assertStringContainsString('<summary>Ce que voit le simulateur</summary><p>Rien ici.</p>', $page);
    }
}
