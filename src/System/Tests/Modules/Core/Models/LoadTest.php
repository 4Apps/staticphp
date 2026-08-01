<?php

namespace System\Tests\Modules\Core\Models;

use PHPUnit\Framework\TestCase;
use System\Modules\Core\Models\Config;
use System\Modules\Core\Models\Load;

class LoadTest extends TestCase
{
    public function testUuid4Shape()
    {
        $test = Load::uuid4();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $test
        );
    }

    public function testUuid4DoesNotRepeat()
    {
        $values = [];
        for ($i = 0; $i < 200; ++$i) {
            $values[] = Load::uuid4();
        }

        $this->assertCount(200, array_unique($values));
    }

    public function testRandomHashShape()
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', Load::randomHash());
    }

    public function testRandomHashDoesNotRepeat()
    {
        $values = [];
        for ($i = 0; $i < 200; ++$i) {
            $values[] = Load::randomHash();
        }

        $this->assertCount(200, array_unique($values));
    }

    /*
    | Template globals
    */

    public function testEnvIsNotExposedToTemplatesByDefault()
    {
        $_ENV['A_SECRET_VALUE'] = 'do not leak';
        Config::set('view_env_keys', []);

        $method = new \ReflectionMethod(Load::class, 'safeEnvForViews');
        $method->setAccessible(true);

        $this->assertEquals([], $method->invoke(null));
    }

    public function testOnlyAllowlistedEnvKeysAreExposed()
    {
        $_ENV['PUBLIC_VALUE'] = 'fine';
        $_ENV['DB_PASSWORD'] = 'do not leak';
        Config::set('view_env_keys', ['PUBLIC_VALUE']);

        $method = new \ReflectionMethod(Load::class, 'safeEnvForViews');
        $method->setAccessible(true);
        $test = $method->invoke(null);

        $this->assertEquals(['PUBLIC_VALUE' => 'fine'], $test);
        $this->assertArrayNotHasKey('DB_PASSWORD', $test);

        Config::set('view_env_keys', []);
    }

    public function testDatabaseConfigIsNotExposedToTemplates()
    {
        Config::set('db', ['pdo' => ['default' => ['password' => 'do not leak']]]);

        $method = new \ReflectionMethod(Load::class, 'safeConfigForViews');
        $method->setAccessible(true);
        $test = $method->invoke(null);

        $this->assertArrayNotHasKey('db', $test);
    }

    public function testSensitiveKeysAreStrippedAtEveryDepth()
    {
        Config::set('some_service', [
            'endpoint' => 'https://example.test',
            'api_key' => 'do not leak',
            'nested' => ['secret' => 'do not leak either', 'public' => 'fine'],
        ]);

        $method = new \ReflectionMethod(Load::class, 'safeConfigForViews');
        $method->setAccessible(true);
        $test = $method->invoke(null);

        $this->assertEquals('https://example.test', $test['some_service']['endpoint']);
        $this->assertArrayNotHasKey('api_key', $test['some_service']);
        $this->assertArrayNotHasKey('secret', $test['some_service']['nested']);
        $this->assertEquals('fine', $test['some_service']['nested']['public']);
    }

    public function testOrdinaryConfigStillReachesTemplates()
    {
        Config::set('a_plain_setting', 'value');

        $method = new \ReflectionMethod(Load::class, 'safeConfigForViews');
        $method->setAccessible(true);
        $test = $method->invoke(null);

        $this->assertEquals('value', $test['a_plain_setting']);
    }
}
