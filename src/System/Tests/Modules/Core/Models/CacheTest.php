<?php

namespace System\Tests\Modules\Core\Models;

use PHPUnit\Framework\TestCase;
use System\Modules\Utils\Models\Cache\Cache;
use System\Modules\Utils\Models\Cache\CacheFiles;

class CacheTest extends TestCase
{
    private static string $path = '';

    public static function setUpBeforeClass(): void
    {
        self::$path = sys_get_temp_dir() . '/staticphp_cache_test_' . bin2hex(random_bytes(6));
    }

    public static function tearDownAfterClass(): void
    {
        if (is_dir(self::$path) === false) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::$path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir(self::$path);
    }

    private function backend(array $extra = []): CacheFiles
    {
        return new CacheFiles(['path' => self::$path] + $extra);
    }

    public function testInit()
    {
        $cache = $this->backend();

        $this->assertInstanceOf(CacheFiles::class, $cache);
        $this->assertDirectoryExists(self::$path);
    }

    public function testDirectoryIsNotWorldWritable()
    {
        $this->backend();

        // 0777 would let any local user drop a file into the cache
        $mode = fileperms(self::$path) & 0777;
        $this->assertSame(0, $mode & 0007, sprintf('cache dir mode is %o', $mode));
    }

    public function testSetAndGetString()
    {
        $cache = $this->backend();
        $cache->setValue('a_string', 'hello');

        $this->assertEquals('hello', $cache->getValue('a_string'));
    }

    public function testSetAndGetNumeric()
    {
        $cache = $this->backend();
        $cache->setValue('a_number', 42);

        $this->assertEquals(42, $cache->getValue('a_number'));
    }

    public function testSetAndGetArray()
    {
        $cache = $this->backend();
        $cache->setValue('an_array', ['one' => 1, 'two' => 2]);

        $this->assertEquals(['one' => 1, 'two' => 2], $cache->getValue('an_array'));
    }

    public function testGetMissingKeyReturnsFalse()
    {
        $cache = $this->backend();

        $this->assertFalse($cache->getValue('never_written'));
    }

    public function testRemoveKey()
    {
        $cache = $this->backend();
        $cache->setValue('to_remove', 'value');

        $this->assertTrue($cache->removeKey('to_remove'));
        $this->assertFalse($cache->getValue('to_remove'));
    }

    public function testRemoveMissingKeyReturnsFalse()
    {
        $cache = $this->backend();

        $this->assertFalse($cache->removeKey('never_written'));
    }

    public function testPrefixIsApplied()
    {
        $cache = $this->backend(['prefix' => 'pfx_']);

        $this->assertEquals('pfx_key', $cache->prefix('key'));
    }

    public function testKeyIsHashedIntoTheFilename()
    {
        $cache = $this->backend();

        // Keys reach the filesystem, so a key containing slashes must not become path
        // components - it is md5'd, leaving a flat hex filename inside the cache dir
        $cache->setValue('../../etc/passwd', 'value');

        $this->assertEquals('value', $cache->getValue('../../etc/passwd'));

        $written = glob(self::$path . '/*.cache');
        $this->assertNotEmpty($written);

        foreach ($written as $file) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.cache$/', basename($file));
            $this->assertSame(realpath(self::$path), dirname(realpath($file)));
        }
    }

    public function testUnsupportedTypeThrows()
    {
        $cache = $this->backend();

        $this->expectException(\Exception::class);
        $cache->setValue('an_object', new \stdClass());
    }

    public function testRegisterAndUseNamedBackend()
    {
        $name = 'files_' . bin2hex(random_bytes(4));
        Cache::register($this->backend(), $name);

        Cache::set('via_facade', 'value', null, $name);

        $this->assertEquals('value', Cache::get('via_facade', $name));
        $this->assertTrue(Cache::remove('via_facade', $name));
    }

    public function testRegisteringTheSameNameTwiceThrows()
    {
        $name = 'dupe_' . bin2hex(random_bytes(4));
        Cache::register($this->backend(), $name);

        $this->expectException(\Exception::class);
        Cache::register($this->backend(), $name);
    }

    public function testUnknownBackendThrows()
    {
        $this->expectException(\Exception::class);
        Cache::get('anything', 'no_such_backend');
    }
}
