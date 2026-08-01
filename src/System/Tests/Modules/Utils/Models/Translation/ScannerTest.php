<?php

namespace System\Tests\Modules\Utils\Models\Translation;

use PHPUnit\Framework\TestCase;
use System\Modules\Utils\Models\Translation\Scanner;

class ScannerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sp_i18n_scan_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->dir . '/' . $name, $contents);
    }

    public function testFindsTheShorthandFunction()
    {
        $this->write('a.php', '<?php echo _(\'Log in\'); echo _("Sign out");');

        $this->assertEquals(['Log in', 'Sign out'], array_keys(Scanner::scan([$this->dir])));
    }

    public function testFindsTheFormatFunction()
    {
        $this->write('a.php', '<?php echo _f(\'{n, plural, one{#} other{#}}\', [\'n\' => 2]);');

        $this->assertArrayHasKey('{n, plural, one{#} other{#}}', Scanner::scan([$this->dir]));
    }

    public function testFindsStaticCalls()
    {
        $this->write('a.php', '<?php i18n::translate(\'Log in\'); i18n::format(\'Count\');');

        $this->assertEquals(['Count', 'Log in'], array_keys(Scanner::scan([$this->dir])));
    }

    public function testFindsTwigFilters()
    {
        $this->write('a.twig', '{{ \'Log in\'|translate }} {{ "Count"  |  format }}');

        $this->assertEquals(['Count', 'Log in'], array_keys(Scanner::scan([$this->dir])));
    }

    public function testRecordsWhereEachOneCameFrom()
    {
        $this->write('a.php', "<?php\n\n_('Log in');\n_('Log in');\n");

        $found = Scanner::scan([$this->dir]);

        $this->assertCount(2, $found['Log in']);
        $this->assertStringEndsWith('a.php:3', $found['Log in'][0]);
        $this->assertStringEndsWith('a.php:4', $found['Log in'][1]);
    }

    public function testEscapesAreResolved()
    {
        $this->write('a.php', '<?php _(\'It\\\'s here\'); _("Tab\\there");');

        $found = array_keys(Scanner::scan([$this->dir]));

        $this->assertContains("It's here", $found);
        $this->assertContains("Tab\there", $found);
    }

    /**
     * Otherwise every helper ending in an underscore looks like the shorthand.
     */
    public function testASimilarlyNamedFunctionIsNotTheShorthand()
    {
        $this->write('a.php', '<?php format_(\'nope\'); $this->_(\'nope\'); $x = $_(\'nope\');');

        $this->assertEquals([], Scanner::scan([$this->dir]));
    }

    public function testOnlyKnownExtensionsAreRead()
    {
        $this->write('a.php', '<?php _(\'yes\');');
        $this->write('b.md', '_(\'no\')');

        $this->assertEquals(['yes'], array_keys(Scanner::scan([$this->dir])));
    }

    public function testASingleFileCanBeScanned()
    {
        $this->write('a.php', '<?php _(\'yes\');');

        $this->assertEquals(['yes'], array_keys(Scanner::scan([$this->dir . '/a.php'])));
    }

    public function testAMissingPathIsNotAnError()
    {
        $this->assertEquals([], Scanner::scan([$this->dir . '/nowhere']));
    }

    /**
     * The limit worth being explicit about: only literals are visible, which is why the
     * scan command says so before anyone prunes on the strength of it.
     */
    public function testAVariableKeyIsInvisible()
    {
        $this->write('a.php', '<?php _($heading); _(SOME_CONSTANT);');

        $this->assertEquals([], Scanner::scan([$this->dir]));
    }
}
