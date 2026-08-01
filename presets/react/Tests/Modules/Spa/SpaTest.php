<?php

namespace Tests\Modules\Spa;

use PHPUnit\Framework\TestCase;
use StaticPHP\Core\Models\Request;

class SpaTest extends TestCase
{
    public function testShellCarriesTheMountPoint(): void
    {
        $response = Request::internal('');

        $this->assertFalse(Request::httpErrorInData($response));
        $this->assertStringContainsString('id="react-root"', $response);
        // Debug decides between app.bundle.js and app.min.js - match the part they share
        $this->assertStringContainsString('assets/dist/js/app.', $response);
    }


    public function testDeepLinksReachTheSameShell(): void
    {
        // A client side route has no controller of its own - the catch-all sends it to the
        // shell so that a reload or a pasted link still boots the app
        $response = Request::internal('items/42/edit');

        $this->assertFalse(Request::httpErrorInData($response));
        $this->assertStringContainsString('id="react-root"', $response);
    }


    public function testApiAnswersJsonAndIsNotSwallowedByTheCatchAll(): void
    {
        $response = Request::internal('api/items');

        $this->assertFalse(Request::httpErrorInData($response));

        $decoded = json_decode($response, true);
        if (is_array($decoded) === false) {
            $this->fail("Expected json from the api, got: {$response}");
        }

        $this->assertArrayHasKey('items', $decoded);
        $this->assertArrayHasKey('served_by', $decoded);
    }
}
