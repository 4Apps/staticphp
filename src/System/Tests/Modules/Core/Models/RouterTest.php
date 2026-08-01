<?php

namespace System\Tests\Modules\Core\Models;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use System\Modules\Core\Controllers\Controller;
use System\Modules\Core\Exceptions\ErrorMessage;
use System\Modules\Core\Exceptions\ErrorMessage\BadRequest;
use System\Modules\Core\Exceptions\ErrorMessage\Forbidden;
use System\Modules\Core\Exceptions\ErrorMessage\NotFound;
use System\Modules\Core\Exceptions\RouterException;
use System\Modules\Core\Models\Config;
use System\Modules\Core\Models\Router;
use System\Modules\Utils\Models\Db;
use System\Modules\Utils\Models\Fv;

class RouterTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::set('allowed_hosts', []);
    }

    /*
    | Segment validation
    |
    | Url segments are rawurldecode()d after being split on "/", so an encoded slash
    | survives inside a single segment and "%2e%2e%2f" arrives as "../".
    */

    #[DataProvider('unsafeSegmentProvider')]
    public function testUnsafeSegmentsAreRejected($segment)
    {
        $this->assertFalse(Router::isSafeSegment($segment));
    }

    public static function unsafeSegmentProvider(): array
    {
        return [
            'encoded traversal' => [rawurldecode('%2e%2e%2f')],
            'plain traversal'   => ['..'],
            'embedded slash'    => ['a/../../b'],
            'backslash'         => ['a\\b'],
            'null byte'         => ["Defaults\0"],
            'leading digit'     => ['1Module'],
            'empty'             => [''],
            'null'              => [null],
            'dot'               => ['.'],
            'absolute path'     => ['/etc/passwd'],
        ];
    }

    #[DataProvider('safeSegmentProvider')]
    public function testSafeSegmentsAreAccepted(string $segment)
    {
        $this->assertTrue(Router::isSafeSegment($segment));
    }

    public static function safeSegmentProvider(): array
    {
        return [
            'simple'      => ['Defaults'],
            'hyphenated'  => ['my-controller'],
            'underscored' => ['my_controller'],
            'with digits' => ['Module2'],
        ];
    }

    /*
    | Path containment
    */

    public function testPathInsideTheRootIsAccepted()
    {
        // The System suite points APP_MODULES_PATH at System/Modules
        $this->assertTrue(
            Router::pathIsWithin(SYS_MODULES_PATH . 'Core/Models/Router.php', SYS_MODULES_PATH)
        );
    }

    public function testTraversalOutOfTheRootIsRejected()
    {
        $this->assertFalse(
            Router::pathIsWithin(SYS_MODULES_PATH . '../../../../etc/passwd', SYS_MODULES_PATH)
        );
    }

    public function testEncodedTraversalOutOfTheRootIsRejected()
    {
        $this->assertFalse(
            Router::pathIsWithin(SYS_MODULES_PATH . rawurldecode('%2e%2e%2f') . 'Tests/autoload.php', SYS_MODULES_PATH)
        );
    }

    public function testSiblingDirectoryIsRejected()
    {
        $this->assertFalse(Router::pathIsWithin(SYS_PATH . 'Tests/autoload.php', SYS_MODULES_PATH));
    }

    public function testMissingPathIsRejected()
    {
        $this->assertFalse(Router::pathIsWithin(SYS_MODULES_PATH . 'nope/nope.php', SYS_MODULES_PATH));
    }

    /*
    | Dispatch visibility
    */

    public function testReflectionCanInvokePrivateMethodsOnThisPhp()
    {
        // Since PHP 8.1 setAccessible() is a no-op and reflection reaches private methods
        // by default. If this ever stops holding, the guard below becomes belt and braces
        // rather than load bearing - which is worth knowing about.
        $method = new \ReflectionMethod(Db::class, 'splitCondition');

        $this->assertEquals(['id', '='], $method->invoke(null, 'id'));
    }

    #[DataProvider('nonRoutableMethodProvider')]
    public function testNonRoutableMethods(string $class, string $name)
    {
        $method = new \ReflectionMethod($class, $name);

        $this->assertFalse(Router::isRoutableMethod($method));
    }

    public static function nonRoutableMethodProvider(): array
    {
        return [
            // Private helpers on a controller must not become endpoints
            'private static'  => [Db::class, 'wrapColumn'],
            'private static 2' => [Db::class, 'buildWhere'],
            // Instance methods cannot be invoked with a null scope
            'public instance' => [Fv::class, 'validate'],
            // Lifecycle hooks are called by loadController() itself
            'construct hook'  => [Controller::class, 'construct'],
        ];
    }

    public function testPublicStaticMethodIsRoutable()
    {
        $method = new \ReflectionMethod(Db::class, 'query');

        $this->assertTrue(Router::isRoutableMethod($method));
    }

    public function testMethodLookupIsCaseInsensitiveSoTheGuardMustBeToo()
    {
        // hasMethod() matches case insensitively, so a private method can be reached
        // through a differently cased url segment
        $ref = new \ReflectionClass(Db::class);

        $this->assertTrue($ref->hasMethod('WRAPCOLUMN'));
        $this->assertFalse(Router::isRoutableMethod($ref->getMethod('WRAPCOLUMN')));
    }

    /*
    | Host header
    */

    public function testListedHostIsAccepted()
    {
        Config::set('allowed_hosts', ['example.com', 'www.example.com']);

        $this->assertEquals('example.com', Router::validateHost('example.com'));
    }

    public function testHostComparisonIsCaseInsensitive()
    {
        Config::set('allowed_hosts', ['example.com']);

        $this->assertEquals('example.com', Router::validateHost('EXAMPLE.com'));
    }

    public function testUnlistedHostIsRejected()
    {
        Config::set('allowed_hosts', ['example.com']);

        // A bad Host header is the client's fault, so it must not land in the 500 path
        $this->expectException(BadRequest::class);
        Router::validateHost('evil.test');
    }

    public function testUnlistedHostCarriesA400()
    {
        Config::set('allowed_hosts', ['example.com']);

        try {
            Router::validateHost('evil.test');
            $this->fail('expected a BadRequest');
        } catch (BadRequest $e) {
            $this->assertEquals(400, $e->httpStatusCode);
        }
    }

    public function testMalformedHostIsRejectedWithoutAnAllowlist()
    {
        $this->expectException(BadRequest::class);
        Router::validateHost("example.com\r\nX-Injected: 1");
    }

    public function testPlainHostIsAcceptedWithoutAnAllowlist()
    {
        $this->assertEquals('localhost:8080', Router::validateHost('localhost:8080'));
    }

    /*
    | Helpers
    */

    public function testFrameworkClassesAreInComposerClassmap()
    {
        // Without an "autoload" section the framework's own classes were invisible to
        // composer, so every one of them fell through to the hand-rolled prober and paid
        // four failed stats before the hit. A failed stat costs ~50x a successful one.
        $classmapFile = BASE_PATH . '../vendor/composer/autoload_classmap.php';
        if (is_file($classmapFile) === false) {
            $this->markTestSkipped('composer autoload not dumped');
        }

        $classmap = require $classmapFile;

        $this->assertArrayHasKey(Router::class, $classmap);
        $this->assertArrayHasKey(ErrorMessage::class, $classmap);
    }

    public function testEnsureStartsWithSlash()
    {
        $this->assertEquals('/a', Router::ensureStartsWithSlash('a'));
        $this->assertEquals('/a', Router::ensureStartsWithSlash('/a'));
        $this->assertEquals('', Router::ensureStartsWithSlash(''));
    }

    public function testUrlToNamespace()
    {
        $this->assertEquals('MyController', Router::urlToNamespace('my-controller'));
    }

    public function testNamespaceToUrl()
    {
        $this->assertEquals('my-controller', Router::namespaceToUrl('MyController'));
    }

    public function testUrlToFileNeedsThreeParts()
    {
        $this->assertFalse(Router::urlToFile('too/short'));

        $test = Router::urlToFile('Module/Class/method');
        $this->assertEquals('Module', $test['module']);
        $this->assertEquals('Class', $test['class']);
        $this->assertEquals('method', $test['method']);
    }
}
