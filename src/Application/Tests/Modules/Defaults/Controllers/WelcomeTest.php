<?php

namespace Tests\Modules\Defaults\Controllers;

use PHPUnit\Framework\TestCase;
use System\Modules\Core\Controllers\Controller;
use System\Modules\Core\Models\Load;
use System\Modules\Core\Models\Router;
use System\Modules\Core\Models\Request;

class WelcomeTest extends TestCase
{
    public function testDefaultController()
    {
        $response = Request::internal('');
        $this->assertNotEmpty($response);
        $this->assertFalse(Request::httpErrorInData($response));
        $this->assertStringContainsString('Welcome', $response);
    }


    public function testUrl()
    {
        $response = Request::internal('defaults/welcome/index');
        $this->assertNotEmpty($response);
        $this->assertFalse(Request::httpErrorInData($response));
        $this->assertStringContainsString('Welcome', $response);
    }


    public function testMissingUrl()
    {
        $response = Request::internal('/non/existant/url');
        $this->assertNotEmpty($response);
        $this->assertTrue(Request::httpErrorInData($response));
    }
}
