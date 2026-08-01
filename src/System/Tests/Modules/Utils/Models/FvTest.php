<?php

namespace System\Tests\Modules\Utils\Models;

use PHPUnit\Framework\TestCase;
use System\Modules\Utils\Models\Fv;

class FvTest extends TestCase
{
    /*
    | xss()
    |
    | A denylist sanitizer that is kept for backwards compatibility. These cover the two
    | defects it had rather than claiming the approach is sound.
    */

    public function testNestedScriptTagDoesNotSurviveStripping()
    {
        // Stripping ran once, so "<scr<script>ipt>" reassembled into a working tag
        $test = Fv::xss('<scr<script>ipt>alert(1)</scr</script>ipt>');

        $this->assertStringNotContainsStringIgnoringCase('<script', $test);
    }

    public function testDoublyNestedScriptTagDoesNotSurvive()
    {
        $test = Fv::xss('<scr<scr<script>ipt>ipt>alert(1)');

        $this->assertStringNotContainsStringIgnoringCase('<script', $test);
    }

    public function testPlainScriptTagIsRemoved()
    {
        $test = Fv::xss('<script>alert(1)</script>');

        $this->assertStringNotContainsStringIgnoringCase('<script', $test);
    }

    public function testIframeIsRemoved()
    {
        $test = Fv::xss('<iframe src="http://example.test"></iframe>');

        $this->assertStringNotContainsStringIgnoringCase('<iframe', $test);
    }

    public function testSvgIsRemoved()
    {
        $test = Fv::xss('<svg onload=alert(1)></svg>');

        $this->assertStringNotContainsStringIgnoringCase('<svg', $test);
    }

    public function testOnAttributesAreRemoved()
    {
        $test = Fv::xss('<img src=x onerror=alert(1)>');

        $this->assertStringNotContainsStringIgnoringCase('onerror', $test);
    }

    public function testPlainTextIsLeftAlone()
    {
        $this->assertEquals('Hello world', Fv::xss('Hello world'));
    }

    /*
    | Validators
    */

    public function testEmailAcceptsLongTlds()
    {
        // The old pattern capped the tld at four characters
        $this->assertTrue(Fv::email('someone@example.technology'));
        $this->assertTrue(Fv::email('someone@example.com'));
    }

    public function testEmailRejectsMalformedAddresses()
    {
        $this->assertFalse(Fv::email('not-an-address'));
        $this->assertFalse(Fv::email('a@b'));
        $this->assertFalse(Fv::email(''));
    }

    public function testLengthExactForm()
    {
        $this->assertTrue(Fv::length('abc', 3));
        $this->assertFalse(Fv::length('abcd', 3));
    }

    public function testLengthAtLeastForm()
    {
        $this->assertTrue(Fv::length('abcdef', 3, '>'));
        $this->assertFalse(Fv::length('ab', 3, '>'));
    }

    public function testLengthAtMostForm()
    {
        // This branch tested '>' a second time, so it was unreachable
        $this->assertTrue(Fv::length('abc', 5, '<'));
        $this->assertFalse(Fv::length('abcdefg', 5, '<'));
    }

    public function testLengthRangeForm()
    {
        $this->assertTrue(Fv::length('abcd', 3, '5'));
        $this->assertFalse(Fv::length('ab', 3, '5'));
    }

    public function testIntegerValidator()
    {
        $this->assertTrue(Fv::integer('123'));
        $this->assertFalse(Fv::integer('12a'));
    }

    public function testIpv4Validator()
    {
        $this->assertTrue(Fv::ipv4('192.168.0.1'));
        $this->assertFalse(Fv::ipv4('999.0.0.1'));
    }

    /*
    | Error messages
    */

    public function testErrorMessagesEscapeTheRejectedValue()
    {
        // The docblock on Fv shows these being echoed directly into a page
        $fv = new Fv(['email' => '<script>alert(1)</script>']);
        $fv->addRules(['email' => ['valid' => ['email']]]);
        $fv->validate();

        $error = $fv->getError('email');

        $this->assertNotFalse($error);
        $this->assertStringNotContainsString('<script>', $error[0]);
        $this->assertStringContainsString('&lt;script&gt;', $error[0]);
    }

    public function testErrorMessagesEscapeTheFieldTitle()
    {
        $fv = new Fv(['name' => '']);
        $fv->addRules([
            'name' => [
                'valid' => ['required'],
                'title' => '<img src=x onerror=alert(1)>',
            ],
        ]);
        $fv->validate();

        $error = $fv->getError('name');

        $this->assertNotFalse($error);
        $this->assertStringNotContainsString('<img', $error[0]);
    }

    public function testMissingFieldProducesAnError()
    {
        $fv = new Fv([]);
        $fv->addRules(['email' => ['valid' => ['required']]]);

        $this->assertFalse($fv->validate());
        $this->assertTrue($fv->hasError('email'));
    }

    public function testValidInputPasses()
    {
        $fv = new Fv(['email' => 'someone@example.com']);
        $fv->addRules(['email' => ['valid' => ['required', 'email']]]);

        $this->assertTrue($fv->validate());
        $this->assertFalse($fv->hasError('email'));
    }

    public function testSetInputValueEscapesItsOutput()
    {
        $fv = new Fv(['name' => '" onfocus="alert(1)']);

        $this->assertStringNotContainsString('" onfocus="', $fv->setInputValue('name'));
    }
}
