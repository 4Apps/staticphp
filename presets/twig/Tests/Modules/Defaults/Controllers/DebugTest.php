<?php

namespace Tests\Modules\Defaults\Controllers;

use Defaults\Controllers\Debug;
use PHPUnit\Framework\TestCase;
use StaticPHP\Core\Exceptions\ErrorMessage\NotFound;
use StaticPHP\Core\Models\Config;
use StaticPHP\Core\Models\Request;
use StaticPHP\Utils\Models\Db;

class DebugTest extends TestCase
{
    protected function setUp(): void
    {
        Config::$items['debug'] = true;
        unset(Config::$items['db']);
    }

    protected function tearDown(): void
    {
        unset(Config::$items['db']);
        Db::close('sqlite_probe');
    }

    /**
     * The report is mixed all the way down - these narrow it, and fail the test rather
     * than the analysis when a key is missing or holds the wrong thing.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function arr(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (is_array($value) === false) {
            $this->fail("Expected an array at '{$key}'");
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (is_string($value) === false) {
            $this->fail("Expected a string at '{$key}'");
        }

        return $value;
    }


    public function testReportsThePhpClockAndTimezone(): void
    {
        $php = $this->arr(Debug::index(), 'php');

        $this->assertNotEmpty($this->str($php, 'version'));
        $this->assertNotEmpty($this->str($php, 'timezone'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $this->str($php, 'time'));
        $this->assertMatchesRegularExpression('/^[-+]\d{2}:\d{2}$/', $this->str($php, 'utc_offset'));
    }


    public function testReportsNoDatabaseWhenNoneIsConfigured(): void
    {
        $this->assertSame(['configured' => false], $this->arr(Debug::index(), 'databases'));
    }


    public function testProbesASqliteConnection(): void
    {
        Config::$items['db']['pdo']['sqlite_probe'] = [
            'string' => 'sqlite::memory:',
            'username' => '',
            'password' => '',
            'wrap_column' => '"',
        ];

        $probe = $this->arr($this->arr(Debug::index(), 'databases'), 'sqlite_probe');

        // The database type is the point of the exercise - a report that says "it works"
        // without saying which engine answered is not worth much
        $this->assertSame('SQLite', $this->str($probe, 'type'));
        $this->assertSame('sqlite', $this->str($probe, 'driver'));
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $this->str($probe, 'version'));
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $this->str($probe, 'time')
        );
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $this->str($probe, 'date'));
        $this->assertNotEmpty($this->str($probe, 'utc_time'));

        // sqlite has no session timezone, and the report has to say so rather than leave
        // the field empty and let it read as "unknown"
        $this->assertStringContainsString('php process timezone', $this->str($probe, 'timezone'));
        $this->assertArrayNotHasKey('error', $probe);
    }


    public function testAnUnreachableConnectionIsReportedRatherThanThrown(): void
    {
        Config::$items['db']['pdo']['sqlite_probe'] = [
            'string' => 'sqlite:/no/such/directory/nothing.sqlite',
            'username' => '',
            'password' => '',
        ];

        $probe = $this->arr($this->arr(Debug::index(), 'databases'), 'sqlite_probe');

        $this->assertNotEmpty($this->str($probe, 'error'));
        $this->assertArrayNotHasKey('time', $probe);
    }


    public function testEveryConfiguredConnectionIsProbed(): void
    {
        Config::$items['db']['pdo']['sqlite_probe'] = [
            'string' => 'sqlite::memory:',
            'username' => '',
            'password' => '',
        ];
        Config::$items['db']['pdo']['broken'] = [
            'string' => 'sqlite:/no/such/directory/nothing.sqlite',
            'username' => '',
            'password' => '',
        ];

        $probes = $this->arr(Debug::index(), 'databases');

        $this->assertSame(['sqlite_probe', 'broken'], array_keys($probes));
        $this->assertSame('SQLite', $this->str($this->arr($probes, 'sqlite_probe'), 'type'));
        $this->assertNotEmpty($this->str($this->arr($probes, 'broken'), 'error'));
    }


    public function testSessionSecretsAreRedacted(): void
    {
        // No active session in the test process, so the session block itself stays null -
        // what is under test is that the redaction reaches nested keys when there is one
        $method = new \ReflectionMethod(Debug::class, 'redact');
        $redacted = $method->invoke(null, [
            'user' => [
                'id' => 7,
                'username' => 'someone',
                'password' => 'hunter2',
                'access' => ['orders' => 'rw'],
            ],
            'token' => 'abcdef',
            'harmless' => 'value',
        ]);

        if (is_array($redacted) === false) {
            $this->fail('Expected an array');
        }

        $user = $this->arr($redacted, 'user');
        $this->assertSame(7, $user['id']);
        $this->assertSame('someone', $this->str($user, 'username'));
        $this->assertSame('** removed **', $this->str($user, 'password'));
        $this->assertSame('** removed **', $this->str($user, 'access'));
        $this->assertSame('** removed **', $this->str($redacted, 'token'));
        $this->assertSame('value', $this->str($redacted, 'harmless'));
    }


    public function testItIsNotReachableWithDebugOff(): void
    {
        Config::$items['debug'] = false;

        $this->expectException(NotFound::class);
        Debug::index();
    }


    /*
    | Through the router, in a separate process
    */

    public function testTheUrlResolvesAndReturnsJson(): void
    {
        $response = Request::internal('defaults/debug');

        $this->assertFalse(Request::httpErrorInData($response));

        $decoded = json_decode($response, true);
        if (is_array($decoded) === false) {
            $this->fail('Expected a json object');
        }

        $this->assertArrayHasKey('php', $decoded);
        $this->assertArrayHasKey('databases', $decoded);
        $this->assertSame('Defaults', $this->str($this->arr($decoded, 'application'), 'module'));
    }
}
