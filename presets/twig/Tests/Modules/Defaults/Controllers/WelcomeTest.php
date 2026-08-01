<?php

namespace Tests\Modules\Defaults\Controllers;

use PHPUnit\Framework\TestCase;
use StaticPHP\Core\Controllers\Controller;
use StaticPHP\Core\Models\Load;
use StaticPHP\Core\Models\Router;
use StaticPHP\Core\Models\Request;

class WelcomeTest extends TestCase
{
    public function testDefaultController(): void
    {
        $response = Request::internal('');
        $this->assertNotEmpty($response);
        $this->assertFalse(Request::httpErrorInData($response));
        $this->assertStringContainsString('Welcome', $response);
    }


    public function testUrl(): void
    {
        $response = Request::internal('defaults/welcome/index');
        $this->assertNotEmpty($response);
        $this->assertFalse(Request::httpErrorInData($response));
        $this->assertStringContainsString('Welcome', $response);
    }


    public function testMissingUrl(): void
    {
        $response = Request::internal('/non/existant/url');
        $this->assertNotEmpty($response);
        $this->assertTrue(Request::httpErrorInData($response));
    }


    /*
    | Status codes
    |
    | These run the front controller in a separate process and read back the status code
    | it set, because that is the part that was wrong: every unresolvable url used to come
    | back as 500.
    */

    /**
     * @return array{0: int, 1: string} [status, body]
     */
    private function request(string $url, bool $production = false): array
    {
        $script = escapeshellarg(__DIR__ . '/../../../status_probe.php');
        $cmd = 'php ' . $script . ' ' . escapeshellarg($url)
            . ($production ? ' --prod' : '') . ' 2>/dev/null';

        exec($cmd, $output, $code);
        $body = implode("\n", $output);

        // The probe prints the status on its own final line
        $lines = explode("\n", $body);
        $status = (int) array_pop($lines);

        return [$status, implode("\n", $lines)];
    }

    public function testResolvedUrlReturns200(): void
    {
        [$status] = $this->request('defaults/welcome/index');

        $this->assertEquals(200, $status);
    }

    public function testUnknownUrlReturns404(): void
    {
        [$status] = $this->request('/non/existant/url');

        $this->assertEquals(404, $status);
    }

    public function testUnknownMethodOnAKnownControllerReturns404(): void
    {
        [$status] = $this->request('defaults/welcome/no-such-method');

        $this->assertEquals(404, $status);
    }

    public function testNotFoundPageDoesNotLeakInternalDetail(): void
    {
        // Run as production, because with debug on the developer page is supposed to
        // carry every one of these
        [$status, $body] = $this->request('/non/existant/url', true);

        $this->assertEquals(404, $status);
        $this->assertStringStartsWith('<!DOCTYPE html>', $body);
        $this->assertStringContainsString('404 Not Found', $body);
        $this->assertStringNotContainsString('Core/Models/Router.php', $body);
        $this->assertStringNotContainsString('No controller for path', $body);
        $this->assertStringNotContainsString('ErrorMessage', $body);
    }

    public function testDebugPageCarriesTheDetailTheStatusPageWithholds(): void
    {
        [$status, $body] = $this->request('/non/existant/url');

        $this->assertEquals(404, $status);
        $this->assertStringContainsString('No controller for path', $body);
        $this->assertStringContainsString('Core/Models/Router.php', $body);
    }
}
