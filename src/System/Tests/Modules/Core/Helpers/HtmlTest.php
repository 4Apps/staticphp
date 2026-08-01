<?php

namespace System\Tests\Modules\Core\Helpers;

use PHPUnit\Framework\TestCase;
use System\Modules\Core\Models\Load;

// Load helper here, because it cannot be loaded more than once.
// The helper lives in the Presentation module - there is no Core/Helpers/Html.php, so
// this used to abort the whole suite with a "failed to open stream" fatal.
Load::helper(['Html'], 'Presentation', 'System');

class HtmlTest extends TestCase
{
    public function testCss()
    {
        html_css('test.css');
        html_css('test2.css');

        $this->expectOutputString(
            '<link rel="stylesheet" type="text/css" href="test.css" />'
                . "\n"
                . '<link rel="stylesheet" type="text/css" href="test2.css" />'
                . "\n"
        );
        html_css();
    }


    public function testJs()
    {
        html_js('test.js');
        html_js('test2.js');

        $this->expectOutputString(
            '<script type="text/javascript" src="test.js"></script>'
                . "\n"
                . '<script type="text/javascript" src="test2.js"></script>'
                . "\n"
        );
        html_js();
    }


    public function testDropdown()
    {
        $dropdown = html_dropdown(
            ['1' => 'One', '2' => 'Two'],
            $selected = 2,
            $addons = ['#' => 'class="test"', '1' => 'data-param="xx"'],
            []
        );

        $this->assertStringContainsString('<select', $dropdown);
        $this->assertStringContainsString('class="test"', $dropdown);
        $this->assertStringContainsString('data-param', $dropdown);
        $this->assertStringContainsString('selected', $dropdown);
        $this->assertStringContainsString('Two', $dropdown);
    }

    public function testDropdownEscapesOptionText()
    {
        $dropdown = html_dropdown(['<img src=x onerror=alert(1)>' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $dropdown);
        $this->assertStringNotContainsString('<img', $dropdown);
        $this->assertStringContainsString('&lt;script&gt;', $dropdown);
    }

    public function testDropdownEscapesOptgroupLabel()
    {
        $dropdown = html_dropdown(['" onmouseover="alert(1)' => ['1' => 'One']]);

        $this->assertStringNotContainsString('" onmouseover="', $dropdown);
        $this->assertStringContainsString('&quot;', $dropdown);
    }

    public function testInputValue()
    {
        $test = html_escape_input('dfsgf"sdfas"sfsf"');
        $this->assertEquals('dfsgf&quot;sdfas&quot;sfsf&quot;', $test);
    }

    public function testTextareaValue()
    {
        // Quotes are escaped too now - the old version only handled < and >, which is
        // not enough for a value that ends up in an attribute
        $test = html_escape_textarea('dfsgf"sdfas"sfsf"> < />');
        $this->assertEquals('dfsgf&quot;sdfas&quot;sfsf&quot;&gt; &lt; /&gt;', $test);
    }

    public function testSelected()
    {
        $current = 1;
        $test = html_set_selected($current, 1);
        $this->assertStringContainsString('selected', $test);

        $current = 2;
        $test = html_set_selected($current, 1);
        $this->assertNull($test);
    }

    public function testChecked()
    {
        $current = 1;
        $test = html_set_checked($current, 1);
        $this->assertStringContainsString('checked', $test);

        $current = 2;
        $test = html_set_selected($current, 1);
        $this->assertNull($test);
    }
}
