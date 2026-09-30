<?php

namespace App\Tests\Command;

use App\Tests\PacksTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ContentCheckCommandTest extends KernelTestCase
{
    use PacksTrait;

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/content-check-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        (new Filesystem())->remove($this->tmp);
        parent::tearDown();
    }

    private function check(string $target): CommandTester
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('content:check'));
        $tester->execute(['target' => $target]);

        return $tester;
    }

    /** Un pack qui ne porte que des articles se vérifie comme les autres : la CI du dépôt de contenu le demande. */
    public function testUnPackDArticlesSansExerciceEstConforme(): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->tmp.'/blog/pack.yaml', "id: blog\ntitle: Blog");
        $filesystem->dumpFile($this->tmp.'/blog/articles/a.md', "---\ntitle: T\ndescription: D\npublished: 2026-10-06\n---\nTexte");
        $this->usePacks($this->tmp);

        $tester = $this->check('blog');

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Pack « blog » chargé, sans exercice à vérifier (1 article(s) conforme(s))', $tester->getDisplay(true));
    }

    public function testUneCibleInconnueResteUneErreur(): void
    {
        $this->usePacks(__DIR__.'/../../../examples/packs');

        $tester = $this->check('inconnu');

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Rien ne correspond à « inconnu »', $tester->getDisplay(true));
    }
}
