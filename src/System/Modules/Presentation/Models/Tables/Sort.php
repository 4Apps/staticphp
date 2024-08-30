<?php

namespace System\Modules\Presentation\Models\Tables;

use System\Modules\Presentation\Models\Tables\Interfaces\ColumnInterface;
use System\Modules\Presentation\Models\Tables\Interfaces\SortInterface;
use System\Modules\Presentation\Models\Tables\Interfaces\TableInterface;
use System\Modules\Presentation\Models\Tables\Enums\SortDirection;
use System\Modules\Presentation\Models\Tables\Column;

/**
 * Html table sort model.
 *
 * Handles table sorting.
 */
class Sort implements SortInterface
{
    /**
     * Table instance
     *
     * (default value: '')
     *
     * @var Table
     * @access protected
     */
    protected Table $tableInstance;

    /**
     * Default column to sort by
     *
     * (default value: null)
     *
     * @var ?Column
     * @access protected
     */
    protected ?Column $defaultColumn = null;

    /**
     * Current/active column to sort by
     *
     * (default value: null)
     *
     * @var ?Column
     * @access protected
     */
    protected ?Column $currentColumn = null;

    /**
     * Current/active column to sort by
     *
     * (default value: SortDirection::ASC)
     *
     * @var SortDirection
     * @access protected
     */
    protected SortDirection $currentDirection = SortDirection::ASC;

    /**
     * String used to parse sort
     *
     * (default value: '')
     *
     * @var string
     * @access protected
     */
    protected string $sortData = '';

    /**
     * Global url prefix
     *
     * (default value: '/')
     *
     * @var string
     * @access protected
     */
    protected string $sortUrlPrefix = '/';


    /**
     * Construct tableSort
     *
     * @access public
     * @param  array    $sort_columns
     * @param  string   $url_prefix (default: [empty string])
     * @return void
     */
    public function __construct(TableInterface &$tableInstance, string $urlPrefix = '', ?string $sortData = null)
    {
        $this->tableInstance = &$tableInstance;
        $this->sortUrlPrefix = $urlPrefix;

        foreach ($this->tableInstance->columns as $column) {
            if ($column->sortDefaultColumn === true) {
                $this->defaultColumn = &$column;
                $this->currentColumn = &$column;
                $this->currentDirection = $this->currentColumn->sortDefaultDirection;
                break;
            }
        }

        if (empty($this->defaultColumn)) {
            throw new \Exception('No default column was found');
        }

        // Parse sortData
        if (!empty($sortData)) {
            $this->parse($sortData);
        }
    }

    /**
     * Set and retrieve url
     *
     * @access public
     * @return string
     */
    public function url(): string
    {
        return $this->sortUrlPrefix;
    }

    /**
     * Set and retrieve url
     *
     * @access public
     * @param  ?string  $setUrl (default: null)
     * @return void
     */
    public function setUrl(?string $setUrl = null): void
    {
        $this->sortUrlPrefix = $setUrl;
    }

    /**
     * Returns current column
     *
     * @access public
     * @return ?ColumnInterface
     */
    public function currentColumn(): ?ColumnInterface
    {
        return $this->currentColumn;
    }

    /**
     * Return current sort direction
     *
     * @access public
     * @return SortDirection
     */
    public function currentDirection(): SortDirection
    {
        return $this->currentDirection;
    }

    /**
     * Parse and find sort by and direction values in sort query string.
     *
     * $sortData format: "[field]=[direction],[field]=[direction],..", e.g. name=asc,created=desc
     *
     * @access public
     * @param  string  $sortData
     * @param  ?string $setPrefix (default: null)
     * @return void
     */
    public function parse(string $sortData): void
    {
        if (!empty($sortData)) {
            $this->sortData = $sortData;
            $sort = $this->tableInstance->parseQueryString($sortData, ';');
            foreach ($sort as $key => $value) {
                if (isset($this->tableInstance->columns[$key])) {
                    $this->currentColumn = &$this->tableInstance->columns[$key];
                    $this->currentDirection = (strtolower($value) == 'desc' ? SortDirection::DESC : SortDirection::ASC);
                    break;
                }
            }
        }
    }

    /**
     * Returns sort as string that was used to parse sorting
     *
     * @access public
     * @return string
     */
    public function sortData(): string
    {
        return $this->sortData;
    }

    /**
     * Get column to sort by against the database
     *
     * @access public
     * @return string   Returns string of sort instructions for database
     */
    public function sortBy(): string
    {
        if (is_callable($this->currentColumn->sortBy)) {
            $sortBy = $this->currentColumn->sortBy;
            return $sortBy($this->currentDirection);
        }

        return $this->currentColumn->sortBy;
    }

    /**
     * Get database compatible sort direction
     *
     * @access public
     * @return SortDirection Returns direction to sort in (asc|desc)
     */
    public function sortDirection(): SortDirection
    {
        if (!empty($this->currentColumn->sortBy) && is_callable($this->currentColumn->sortBy)) {
            return SortDirection::NONE; // Custom sort function
        }

        return $this->currentDirection;
    }
}
