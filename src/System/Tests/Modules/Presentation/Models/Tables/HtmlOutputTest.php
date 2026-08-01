<?php

namespace System\Tests\Modules\Presentation\Models\Tables;

use PHPUnit\Framework\TestCase;
use System\Modules\Presentation\Models\Tables\Column;
use System\Modules\Presentation\Models\Tables\Output\Html;
use System\Modules\Presentation\Models\Tables\Table;

/**
 * The table renderer assembles markup by concatenation, so everything it emits goes
 * through Html::escape().
 */
class HtmlOutputTest extends TestCase
{
    public function testAngleBracketsAreEscaped()
    {
        $this->assertEquals(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            Html::escape('<script>alert(1)</script>')
        );
    }

    public function testDoubleQuoteIsEscaped()
    {
        $this->assertEquals('&quot; onfocus=alert(1) x=&quot;', Html::escape('" onfocus=alert(1) x="'));
    }

    public function testSingleQuoteIsEscaped()
    {
        // Escaping only the double quote leaves single quoted attributes exploitable
        $this->assertEquals('&#039; onfocus=alert(1)', Html::escape("' onfocus=alert(1)"));
    }

    public function testAmpersandIsEscaped()
    {
        $this->assertEquals('a&amp;b', Html::escape('a&b'));
    }

    public function testNullAndScalarsAreTolerated()
    {
        $this->assertEquals('', Html::escape(null));
        $this->assertEquals('42', Html::escape(42));
        $this->assertEquals('1.5', Html::escape(1.5));
    }

    public function testArraysAndObjectsDoNotLeakTheirContents()
    {
        $this->assertEquals('', Html::escape(['<script>']));
        $this->assertEquals('', Html::escape(new \stdClass()));
    }

    public function testInvalidUtf8IsSubstitutedRatherThanDropped()
    {
        // Without ENT_SUBSTITUTE htmlspecialchars returns "" for invalid utf-8, which
        // silently discards the value instead of escaping it
        $this->assertNotEquals('', Html::escape("valid\xB1text"));
    }

    private function output(): Html
    {
        // The TableInstance trait takes the owning table by reference
        $table = new Table([new Column('test')]);

        return new Html($table);
    }

    public function testInputValueEscapesTheAttribute()
    {
        $attribute = $this->output()->inputValue('" onfocus="alert(1)');

        $this->assertStringNotContainsString('" onfocus="', $attribute);
        $this->assertStringContainsString('&quot;', $attribute);
    }

    public function testInputValueComparisonStillWorks()
    {
        $output = $this->output();

        $this->assertEquals(' selected="selected"', $output->inputValue('a', 'a'));
        $this->assertEquals('', $output->inputValue('a', 'b'));
        $this->assertEquals(' checked="checked"', $output->inputValue('a', 'a', true));
    }

    public function testColumnsEscapeTheirDataByDefault()
    {
        // Escaping used to be opt in, which meant every column rendered raw unless the
        // application remembered to turn it on
        $column = new Column('test');

        $this->assertTrue($column->escapeDataHtml);
    }
}
