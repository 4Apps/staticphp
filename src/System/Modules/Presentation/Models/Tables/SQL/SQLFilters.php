<?php

namespace System\Modules\Presentation\Models\Tables\SQL;

use System\Modules\Presentation\Models\Tables\Column;
use System\Modules\Presentation\Models\Tables\Enums\ColumnType;
use System\Modules\Presentation\Models\Tables\Interfaces\TableInstanceInterface;
use System\Modules\Presentation\Models\Tables\Traits\TableInstance;
use System\Modules\Presentation\Models\Tables\Utils;

/**
 * SQL Filters implementation
 */
class SQLFilters implements TableInstanceInterface
{
    use TableInstance;

    /**
     * Array of database query strings containing all filters used in search
     *
     * (default value: [])
     *
     * @var array
     * @access protected
     */
    protected array $queries = [];

    /**
     * Array of all parametrs
     *
     * (default value: [])
     *
     * @var array
     * @access protected
     */
    protected array $params = [];

    /**
     * Array of all parametrs by filter key
     *
     * (default value: [])
     *
     * @var array
     * @access protected
     */
    protected array $paramsByKey = [];


    /**
     * Returns boolean specifying whether there is any query in use
     *
     * @access public
     * @return bool
     */
    public function hasQuery(): bool
    {
        return empty($this->queries) === false;
    }

    /**
     * Returns all queries
     *
     * @access public
     * @return array
     */
    public function queries(): array
    {
        return $this->queries;
    }

    /**
     * Returns array of filter values
     *
     * @access public
     * @param  ?string $key Key
     * @return ?array
     */
    public function params(?string $key = null): ?array
    {
        if (!empty($key)) {
            return $this->paramsByKey[$key] ?? null;
        }

        return $this->params;
    }

    /**
     * Returns $prefix concatenated with filter keys for SQL query
     *
     * @access public
     * @param  string $prefix
     * @return string
     */
    public function querySql(string $prefix = 'WHERE'): string
    {
        if (empty($this->queries) === true) {
            return '';
        }

        return " {$prefix} " . implode(' AND ', $this->queries);
    }

    /**
     * Return $value as it is or transform it to unix timestamp
     */
    public static function strtotime(string $value, bool $sqlDate = false)
    {
        return ($sqlDate === true ? $value : strtotime($value));
    }

    public static function valueToQuery(
        string $fieldName,
        $value,
        string $compare = '=',
        ?\Closure $valueFormatter = null,
        $nullQuery = false
    ): array {
        // NULL is a special case as there is no value to check
        if ($nullQuery === true) {
            if ($compare === '!') {
                return ["{$fieldName} IS NOT NULL", []];
            }

            return ["{$fieldName} IS NULL", []];
        }

        // Multi-select filters hand us an array. Treat it as an IN list rather than letting
        // it reach preg_match() below, which would raise a TypeError on an array argument.
        if (is_array($value)) {
            if (empty($value)) {
                return ['1 = 0', []];
            }

            $value = array_map(
                function ($item) use ($valueFormatter) {
                    return Utils::valueOrClosure($item, $valueFormatter);
                },
                array_values($value)
            );

            $query = "{$fieldName} IN (" . implode(', ', array_fill(0, count($value), '?')) . ')';

            return [$query, $value];
        }

        $value = (string) $value;

        // Check for range
        $regex = '/(.+)(~)(.+)/';
        $matches = [];
        $match = preg_match($regex, $value, $matches);
        if ($match === 1) {
            $query = "{$fieldName} >= ? AND {$fieldName} <= ?";
            $params = [
                Utils::valueOrClosure($matches[1], $valueFormatter),
                Utils::valueOrClosure($matches[3], $valueFormatter)
            ];

            return [$query, $params];
        }

        // Find value
        $queryValue = $value;
        if (!empty($value[0]) && in_array($value[0], ['=', '<', '>', '!', '@', '^', '$', '%'])) {
            $compare = $value[0];
            $queryValue = substr($value, 1);
        }

        // Format value.
        // For the IN comparison every element is formatted individually and then bound as
        // its own placeholder below - array_map is deliberately given a single array here,
        // because passing the formatter as a second array pads it with nulls and silently
        // skips the formatter for every element after the first.
        $queryValues = [];
        if ($compare == '@') {
            $queryValues = array_map(
                function ($value) use ($valueFormatter) {
                    return Utils::valueOrClosure($value, $valueFormatter);
                },
                explode(',', $queryValue)
            );
        } else {
            $queryValue = Utils::valueOrClosure($queryValue, $valueFormatter);
        }

        // Figure out query and params
        $query = "";
        $params = [];
        switch ($compare) {
            case '=':
                $query = "{$fieldName} = ?";
                $params = [$queryValue];
                break;
            case '<':
                $query = "{$fieldName} <= ?";
                $params = [$queryValue];
                break;
            case '>':
                $query = "{$fieldName} >= ?";
                $params = [$queryValue];
                break;
            case '!':
                $query = "{$fieldName} != ?";
                $params = [$queryValue];
                break;
            case '@':
                // An empty list has no valid SQL representation, so collapse it to a
                // constant with the same truth value instead of emitting "IN ()"
                if (empty($queryValues)) {
                    $query = '1 = 0';
                    break;
                }

                $query = "{$fieldName} IN (" . implode(', ', array_fill(0, count($queryValues), '?')) . ')';
                $params = $queryValues;
                break;
            case '^':
                $query = "{$fieldName}::TEXT ILIKE ?";
                $params = ["{$queryValue}%"];
                break;
            case '$':
                $query = "{$fieldName}::TEXT ILIKE ?";
                $params = ["%{$queryValue}"];
                break;
            default:
                $query = "{$fieldName}::TEXT ILIKE ?";
                $params = ["%{$queryValue}%"];
                break;
        }

        return [$query, $params];
    }

    /**
     * Run local filter funcation based on filter_type and return query for filter_by table column.
     *
     * @access public
     * @param  Column   $filterColumn
     * @param  mixed    $value
     * @return string[] Array of resulting query, params and data(string[])
     */
    public static function runFilter(Column $filterColumn, $value): ?array
    {
        $columnType = $filterColumn->type;
        $filterBy = $filterColumn->filterBy;

        // NULL Query
        $nullQuery = false;
        if ($filterColumn->filterZeroIsNULL === true && ($value === 0 || $value === '0')) {
            $nullQuery = true;
        }

        // TEXT
        if ($columnType === ColumnType::TEXT) {
            list($query, $params) = self::valueToQuery($filterBy, $value, '%', nullQuery: $nullQuery);
            return [
                'query' => $query,
                'param' => $params,
                'data'  => [
                    'title' => $value,
                    'value' => $value,
                ]
            ];
        }

        // TEXT_EXACT
        if ($columnType === ColumnType::TEXT_EXACT) {
            list($query, $params) = self::valueToQuery($filterBy, $value, '=', nullQuery: $nullQuery);
            return [
                'query' => $query,
                'param' => $params,
                'data'  => [
                    'title' => $value,
                    'value' => $value,
                ]
            ];
        }

        // INT8
        if ($columnType === ColumnType::INT) {
            list($query, $params) = self::valueToQuery(
                $filterBy,
                $value,
                '=',
                function ($value) {
                    $value = (int)$value;
                    return (int)$value;
                },
                nullQuery: $nullQuery
            );
            return [
                'query' => $query,
                'param' => $params,
                'data'  => [
                    'title' => $value,
                    'value' => $value,
                ]
            ];
        }

        // DECIMAL
        if ($columnType === ColumnType::DECIMAL) {
            $value = str_replace(',', '.', $value);
            list($query, $params) = self::valueToQuery(
                $filterBy,
                $value,
                '=',
                function ($value) {
                    return (float)$value;
                },
                nullQuery: $nullQuery
            );
            return [
                'query' => $query,
                'param' => $params,
                'data'  => [
                    'title' => $value,
                    'value' => $value,
                ]
            ];
        }

        // BOOLEAN
        if ($columnType === ColumnType::BOOLEAN) {
            list($query, $params) = self::valueToQuery(
                $filterBy,
                $value,
                '=',
                function ($value) {
                    if (strtolower($value) === 'false') {
                        $value = false;
                    } elseif (strtolower($value) === 'true') {
                        $value = true;
                    }

                    return (int)$value;
                },
                nullQuery: $nullQuery
            );
            return [
                'query' => $query,
                'param' => $params,
                'data'  => [
                    'title' => $value,
                    'value' => $value,
                ]
            ];
        }

        // DATETIME
        if (
            $columnType === ColumnType::DATETIME || $columnType === ColumnType::DATETIME_NATIVE
            || $columnType === ColumnType::DATE || $columnType === ColumnType::DATE_NATIVE
        ) {
            $field = $filterBy;
            $start = preg_replace(
                '/^([0-9]{2})\.([0-9]{2})\.([0-9]{4}) ([0-9]{2}):([0-9]{2})$/',
                '$3-$2-$1 $4:$5',
                $value
            );
            $stop = preg_replace(
                '/.*([0-9]{2})\.([0-9]{2})\.([0-9]{4}) $4:$5$/',
                '$3-$2-$1 $4:$5',
                $value
            );
            if (
                preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2})/', $start)
                && preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2})/', $stop)
            ) {
                return [
                    'query' => "{$field} >= ? AND {$field} <= ? ",
                    'param' => [
                        $columnType === ColumnType::DATE || $columnType === ColumnType::DATETIME
                            ? self::strtotime($start, $filterColumn->filterSqlDate)
                            : $start,
                        $columnType === ColumnType::DATE || $columnType === ColumnType::DATETIME
                            ? self::strtotime($stop, $filterColumn->filterSqlDate)
                            : $stop
                    ],
                    'data'  => [
                        'title' => $value,
                        'value' => $value,
                    ]
                ];
            } elseif (preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2})/', $start)) {
                $startQ = "{$start}";
                return [
                    'query' => "{$field} = ?",
                    'param' => [
                        $columnType === ColumnType::DATETIME
                            ? self::strtotime($startQ, $filterColumn->filterSqlDate)
                            : $startQ
                    ],
                    'data'  => [
                        'title' => $value,
                        'value' => $value,
                    ]
                ];
            }

            $start = preg_replace('/^([0-9]{2})\.([0-9]{2})\.([0-9]{4})?.*/', '$3-$2-$1', $value);
            $stop = preg_replace('/.*([0-9]{2})\.([0-9]{2})\.([0-9]{4})$/', '$3-$2-$1', $value);
            if (
                preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2})/', $start)
                && preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2})/', $stop)
            ) {
                $startQ = "{$start} 00:00:00";
                $endQ = "{$stop} 23:59:59";

                return [
                    'query' => "{$field} >= ? AND {$field} <= ? ",
                    'param' => [
                        $columnType === ColumnType::DATE || $columnType === ColumnType::DATETIME
                            ? self::strtotime($startQ, $filterColumn->filterSqlDate)
                            : $startQ,
                        $columnType === ColumnType::DATE || $columnType === ColumnType::DATETIME
                            ? self::strtotime($endQ, $filterColumn->filterSqlDate)
                            : $endQ
                    ],
                    'data'  => [
                        'title' => $value,
                        'value' => $value,
                    ]
                ];
            } elseif (preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2})/', $start)) {
                $startQ = "{$start} 00:00:00";
                $endQ = "{$start} 23:59:59";
                return [
                    'query' => "{$field} >= ? AND {$field} <= ? ",
                    'param' => [
                        $columnType === ColumnType::DATE || $columnType === ColumnType::DATETIME
                            ? self::strtotime($startQ, $filterColumn->filterSqlDate)
                            : $startQ,
                        $columnType === ColumnType::DATE || $columnType === ColumnType::DATETIME
                            ? self::strtotime($endQ, $filterColumn->filterSqlDate)
                            : $endQ
                    ],
                    'data'  => [
                        'title' => $value,
                        'value' => $value,
                    ]
                ];
            }

            return null;
        }

        return null;
    }

    public function prepareQueries(?\Closure $formatter = null)
    {
        $parsedData = $this->tableInstance->filter->parsedData();
        foreach ($parsedData as $key => $valueData) {
            if (!isset($this->tableInstance->columns[$key])) {
                continue;
            }

            $filterColumn = $this->tableInstance->columns[$key];
            $value = $valueData['value'];
            $data = [];

            // Run filter methods
            if (!empty($filterColumn->filterBy)) {
                if (is_callable($filterColumn->filterBy)) {
                    $filterBy = $filterColumn->filterBy;
                    $data = $filterBy($value);
                } else {
                    $data = self::runFilter($filterColumn, $value);
                }
            } elseif ($formatter !== null) {
                $data = $formatter($filterColumn, $value);
            }

            // Collect queries
            if (isset($data['query'])) {
                $this->queries[] = $data['query'];
            }

            // Collect params
            if (isset($data['param'])) {
                if (is_array($data['param'])) {
                    $this->params = array_merge($this->params, $data['param']);
                } else {
                    $this->params[] = $data['param'];
                }
                $this->paramsByKey[$key] = $data['param'];
            }

            // Collect filter data
            if (isset($filterColumn->filterData)) {
                if (is_callable($filterColumn->filterData)) {
                    $filterData = $filterColumn->filterData;
                    $test = $filterData($value);
                    if ($test !== null) {
                        $this->tableInstance->filter->setParsedData($key, $test);
                    }
                } elseif (is_array($filterColumn->filterData)) {
                    $this->tableInstance->filter->setParsedData($key, $filterColumn->filterData);
                }
            } elseif (isset($data['data'])) {
                $this->tableInstance->filter->setParsedData($key, $data['data']);
            }
        }
    }
}
