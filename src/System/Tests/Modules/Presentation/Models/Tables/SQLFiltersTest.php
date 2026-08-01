<?php

namespace System\Tests\Modules\Presentation\Models\Tables;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use System\Modules\Presentation\Models\Tables\SQL\SQLFilters;

/**
 * Filter values come straight from the request, so valueToQuery() decides whether user
 * input is bound or written into the query.
 */
class SQLFiltersTest extends TestCase
{
    public function testDefaultComparisonBindsTheValue()
    {
        [$sql, $params] = SQLFilters::valueToQuery('col', 'needle');

        $this->assertEquals('col = ?', $sql);
        $this->assertEquals(['needle'], $params);
    }

    public function testWildcardComparisonBindsTheValue()
    {
        [$sql, $params] = SQLFilters::valueToQuery('col', '%needle');

        $this->assertEquals('col::TEXT ILIKE ?', $sql);
        $this->assertEquals(['%needle%'], $params);
    }

    public function testInComparisonUsesPlaceholders()
    {
        [$sql, $params] = SQLFilters::valueToQuery('col', '@a,b,c');

        $this->assertEquals('col IN (?, ?, ?)', $sql);
        $this->assertEquals(['a', 'b', 'c'], $params);
    }

    /**
     * The IN branch used to escape by doubling single quotes and interpolating the result,
     * which is only safe under standard_conforming_strings.
     */
    public function testInComparisonDoesNotInterpolateQuotes()
    {
        // Splitting on the comma leaves a quote on each side; both are bound rather than
        // escaped and concatenated, so neither reaches the query text
        [$sql, $params] = SQLFilters::valueToQuery('col', "@x','y");

        $this->assertStringNotContainsString("'", $sql);
        $this->assertEquals('col IN (?, ?)', $sql);
        $this->assertEquals(["x'", "'y"], $params);
    }

    public function testInComparisonSurvivesABackslashPayload()
    {
        [$sql, $params] = SQLFilters::valueToQuery('col', "@a\\',b");

        $this->assertStringNotContainsString('\\', $sql);
        $this->assertEquals('col IN (?, ?)', $sql);
    }

    /**
     * array_map was given the formatter as a one element array, so php padded it with
     * nulls and the cast applied only to the first element.
     */
    public function testFormatterAppliesToEveryElementOfAnInList()
    {
        $toInt = function ($value) {
            return (int) $value;
        };

        [$sql, $params] = SQLFilters::valueToQuery('col', '@1,2abc,3def', '=', $toInt);

        $this->assertEquals([1, 2, 3], $params);
    }

    public function testBareInPrefixBindsAnEmptyValue()
    {
        // explode() always yields at least one element, so "@" on its own is a filter for
        // the empty string rather than an empty list - still bound, not interpolated
        [$sql, $params] = SQLFilters::valueToQuery('col', '@');

        $this->assertEquals('col IN (?)', $sql);
        $this->assertEquals([''], $params);
    }

    public function testArrayValueBecomesABoundInList()
    {
        [$sql, $params] = SQLFilters::valueToQuery('col', ['a', 'b']);

        $this->assertEquals('col IN (?, ?)', $sql);
        $this->assertEquals(['a', 'b'], $params);
    }

    public function testEmptyArrayValueCollapsesToFalse()
    {
        [$sql, $params] = SQLFilters::valueToQuery('col', []);

        $this->assertEquals('1 = 0', $sql);
    }

    public function testRangeComparisonBindsBothBounds()
    {
        [$sql, $params] = SQLFilters::valueToQuery('col', '10~20');

        $this->assertEquals('col >= ? AND col <= ?', $sql);
        $this->assertEquals(['10', '20'], $params);
    }

    public function testNullQueryNeedsNoParameters()
    {
        [$sql, $params] = SQLFilters::valueToQuery('col', '', '=', null, true);

        $this->assertEquals('col IS NULL', $sql);
        $this->assertEquals([], $params);

        [$sql, $params] = SQLFilters::valueToQuery('col', '', '!', null, true);

        $this->assertEquals('col IS NOT NULL', $sql);
    }

    #[DataProvider('comparisonPrefixProvider')]
    public function testComparisonPrefixesBindTheirValue(string $value, string $expectedSql)
    {
        [$sql, $params] = SQLFilters::valueToQuery('col', $value);

        $this->assertEquals($expectedSql, $sql);
        $this->assertCount(1, $params);
    }

    public static function comparisonPrefixProvider(): array
    {
        return [
            'equals'       => ['=x', 'col = ?'],
            'less than'    => ['<x', 'col <= ?'],
            'greater than' => ['>x', 'col >= ?'],
            'not equal'    => ['!x', 'col != ?'],
            'starts with'  => ['^x', 'col::TEXT ILIKE ?'],
            'ends with'    => ['$x', 'col::TEXT ILIKE ?'],
        ];
    }
}
