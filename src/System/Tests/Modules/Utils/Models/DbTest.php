<?php

namespace System\Tests\Modules\Utils\Models;

use PHPUnit\Framework\TestCase;
use System\Modules\Utils\Models\Db;

/**
 * Db builds identifiers and conditions by string concatenation, so these cover the
 * boundary between what is bound as a parameter and what is written into the query.
 */
class DbTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // buildWhere() reads wrap_column off the connection config, so seed one without
        // opening an actual connection
        $configs = new \ReflectionProperty(Db::class, 'dbConfigs');
        $configs->setValue(null, ['default' => ['wrap_column' => '`']]);
    }

    /**
     * @return array [sql, params]
     */
    private function where($where): array
    {
        $method = new \ReflectionMethod(Db::class, 'buildWhere');

        $params = [];
        $sql = $method->invokeArgs(null, [$where, 'default', &$params]);

        return [$sql, $params];
    }

    public function testScalarConditionIsBound()
    {
        [$sql, $params] = $this->where(['id' => 5]);

        $this->assertEquals('WHERE `id` = ?', $sql);
        $this->assertEquals([5], $params);
    }

    public function testArrayConditionUsesPlaceholders()
    {
        [$sql, $params] = $this->where(['id' => [1, 2, 3]]);

        $this->assertEquals('WHERE `id` IN (?, ?, ?)', $sql);
        $this->assertEquals([1, 2, 3], $params);
    }

    public function testNegatedArrayConditionUsesPlaceholders()
    {
        [$sql, $params] = $this->where(['!id' => [4, 5]]);

        $this->assertEquals('WHERE `id` NOT IN (?, ?)', $sql);
        $this->assertEquals([4, 5], $params);
    }

    /**
     * An array value used to be imploded straight into the query, so anything that could
     * make a scalar arrive as an array turned a parameterized call into an injectable one.
     */
    public function testArrayConditionDoesNotInterpolateItsValues()
    {
        [$sql, $params] = $this->where(['id' => ['1) OR 1=1 -- ']]);

        $this->assertEquals('WHERE `id` IN (?)', $sql);
        $this->assertStringNotContainsString('OR 1=1', $sql);
        $this->assertEquals(['1) OR 1=1 -- '], $params);
    }

    public function testEmptyInListCollapsesToFalse()
    {
        [$sql, $params] = $this->where(['id' => []]);

        $this->assertEquals('WHERE 1 = 0', $sql);
        $this->assertEquals([], $params);
    }

    public function testEmptyNotInListCollapsesToTrue()
    {
        [$sql, $params] = $this->where(['!id' => []]);

        $this->assertEquals('WHERE 1 = 1', $sql);
    }

    public function testOperatorInKeyIsHonoured()
    {
        [$sql, $params] = $this->where(['age >' => 18]);

        $this->assertEquals('WHERE `age` > ?', $sql);
        $this->assertEquals([18], $params);
    }

    public function testMultiWordOperatorIsHonoured()
    {
        [$sql, $params] = $this->where(['name NOT LIKE' => 'x%']);

        $this->assertEquals('WHERE `name` NOT LIKE ?', $sql);
    }

    public function testQualifiedColumnIsWrappedPerPart()
    {
        [$sql, $params] = $this->where(['t.col' => 1]);

        $this->assertEquals('WHERE `t`.`col` = ?', $sql);
    }

    public function testConditionsAreJoinedWithAnd()
    {
        [$sql, $params] = $this->where(['a' => 1, 'b' => 2]);

        $this->assertEquals('WHERE `a` = ? AND `b` = ?', $sql);
        $this->assertEquals([1, 2], $params);
    }

    public function testColumnNameBreakingOutOfTheQuotingIsRejected()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->where(['id`) OR 1=1 -- ' => 1]);
    }

    public function testColumnNameWithASpaceIsRejected()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->where(['id;DROP TABLE users' => 1]);
    }

    public function testUnknownOperatorIsRejected()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->where(['id UNION' => 1]);
    }

    public function testDeleteRequiresACondition()
    {
        $this->expectException(\InvalidArgumentException::class);
        Db::delete('posts', []);
    }

    /*
    | Connection handling
    */

    public function testUnknownConnectionNameNamesTheProblem()
    {
        // `&self::$dbLinks[$name]` auto-vivified a null entry, so a typo surfaced later as
        // "call to a member function on null" and left the bogus key behind
        try {
            Db::commit('no_such_connection');
            $this->fail('expected an exception');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('no_such_connection', $e->getMessage());
        }
    }

    public function testUnknownConnectionNameIsNotRemembered()
    {
        try {
            Db::commit('typo_connection');
        } catch (\Throwable $e) {
            // expected
        }

        $links = new \ReflectionProperty(Db::class, 'dbLinks');

        $this->assertArrayNotHasKey('typo_connection', (array) $links->getValue());
    }
}
