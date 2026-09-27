<?php

namespace App\Tests\Ai;

use App\Ai\PromptFiles;
use PHPUnit\Framework\TestCase;

final class PromptFilesTest extends TestCase
{
    public function testChaqueFichierSousSonEnTete(): void
    {
        $this->assertSame("--- a.php ---\n<?php\n\n--- b.md ---\n# B", PromptFiles::render(['a.php' => '<?php', 'b.md' => '# B']));
        $this->assertSame('', PromptFiles::render([]));
    }

    public function testUnFichierTropLongEstTronque(): void
    {
        $this->assertSame("--- a.txt ---\nabc\n… (tronqué)", PromptFiles::render(['a.txt' => 'abcdef'], 3));
        $this->assertSame('abc', PromptFiles::truncate('abc', 3));
    }
}
