<?php

namespace System\Tests\Modules\Utils\Models;

use PHPUnit\Framework\TestCase;
use System\Modules\Utils\Models\Csrf;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        // The token lives in the session, and phpunit has already written output by the
        // time these run, so start one explicitly rather than through session_start()
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        }

        Csrf::reset();
    }

    /**
     * Csrf::token() requires an active session; fake one so the class under test sees
     * PHP_SESSION_ACTIVE without phpunit's own output getting in the way.
     */
    private function withSession(callable $body)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            @session_start();
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            $this->markTestSkipped('no session available in this environment');
        }

        return $body();
    }

    public function testTokenIsLongAndHex()
    {
        $this->withSession(function () {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Csrf::token());
        });
    }

    public function testTokenIsStableWithinASession()
    {
        $this->withSession(function () {
            $this->assertEquals(Csrf::token(), Csrf::token());
        });
    }

    public function testCorrectTokenValidates()
    {
        $this->withSession(function () {
            $this->assertTrue(Csrf::validate(Csrf::token()));
        });
    }

    public function testWrongTokenOfEqualLengthIsRejected()
    {
        $this->withSession(function () {
            Csrf::token();
            $this->assertFalse(Csrf::validate(str_repeat('0', 64)));
        });
    }

    public function testTokenPrefixIsRejected()
    {
        $this->withSession(function () {
            $token = Csrf::token();
            $this->assertFalse(Csrf::validate(substr($token, 0, 32)));
        });
    }

    public function testEmptyAndNullTokensAreRejected()
    {
        $this->withSession(function () {
            Csrf::token();
            $this->assertFalse(Csrf::validate(''));
            $this->assertFalse(Csrf::validate(null));
        });
    }

    public function testRequestValidationReadsPost()
    {
        $this->withSession(function () {
            $_POST[Csrf::FIELD_NAME] = Csrf::token();

            $this->assertTrue(Csrf::validateRequest());

            unset($_POST[Csrf::FIELD_NAME]);
        });
    }

    public function testRequestValidationReadsTheHeader()
    {
        $this->withSession(function () {
            $_SERVER[Csrf::HEADER_NAME] = Csrf::token();

            $this->assertTrue(Csrf::validateRequest());

            unset($_SERVER[Csrf::HEADER_NAME]);
        });
    }

    public function testRequestWithoutATokenIsRejected()
    {
        $this->withSession(function () {
            Csrf::token();
            unset($_POST[Csrf::FIELD_NAME], $_SERVER[Csrf::HEADER_NAME]);

            $this->assertFalse(Csrf::validateRequest());
        });
    }

    public function testArrayTokenDoesNotBypassValidation()
    {
        $this->withSession(function () {
            Csrf::token();
            $_POST[Csrf::FIELD_NAME] = ['array', 'payload'];

            $this->assertFalse(Csrf::validateRequest());

            unset($_POST[Csrf::FIELD_NAME]);
        });
    }

    public function testFieldRendersAHiddenInputCarryingTheToken()
    {
        $this->withSession(function () {
            $token = Csrf::token();
            $field = Csrf::field();

            $this->assertStringContainsString('type="hidden"', $field);
            $this->assertStringContainsString('name="' . Csrf::FIELD_NAME . '"', $field);
            $this->assertStringContainsString($token, $field);
        });
    }

    public function testResetIssuesANewToken()
    {
        $this->withSession(function () {
            $token = Csrf::token();
            Csrf::reset();

            $this->assertNotEquals($token, Csrf::token());
        });
    }
}
