<?php

namespace System\Modules\Presentation\Models\Tables;

use System\Modules\Presentation\Models\Tables\Interfaces\TableInterface;
use System\Modules\Presentation\Models\Tables\Interfaces\OutputInterface;
use System\Modules\Presentation\Models\Tables\Enums\RowPosition;

class Table implements TableInterface
{
    public ?Sort $sort = null;
    public ?Filters $filter = null;
    public ?Pagination $pagination = null;
    public ?OutputInterface $outputGenerator = null;

    public ?array $columns = null;
    public ?array $rows = null;
    public ?array $children = null;

    public null|\Closure $initRow = null;

    public ?array $avgRow = null;
    public RowPosition $avgRowPosition = RowPosition::BODY_TOP;

    public ?array $sumRow = null;
    public RowPosition $sumRowPosition = RowPosition::BODY_TOP;

    public ?array $customRow = null;
    public RowPosition $customRowPosition = RowPosition::BODY_TOP;

    public ?array $beforeDataRow = null;
    public ?array $afterDataRow = null;

    public bool|\Closure $isEditable = false;
    public null|string|\Closure $idKey = null;

    /**
     * Unique table id
     *
     * (default value: '')
     *
     * @var string
     * @access protected
     */
    protected string $tableId = '';

    /**
     * Url prefix
     *
     * (default value: '')
     *
     * @var string
     * @access protected
     */
    protected string $urlPrefix = '';


    public function __construct(
        array $columns,
        string $urlPrefix = ''
    ) {
        // time() plus one of a hundred values is guessable; this only needs to be unique
        // within a page, but a predictable id is a needless hint to anything scripting it
        $this->tableId = bin2hex(random_bytes(8));
        $this->urlPrefix = $urlPrefix;

        $this->setColumns($columns);
    }


    /**
     * Returns table's unique id
     *
     * @access public
     * @return string
     */
    public function tableId(): string
    {
        return $this->tableId;
    }

    /**
     * Parse query string using $delimiter
     *
     * @param string $str Query string
     * @param string $delimiter Delimiter
     * @return array
     */
    public function parseQueryString(string $str, string $delimiter = '&')
    {
        $op = [];
        $pairs = explode($delimiter, $str);
        foreach ($pairs as $pair) {
            $ex = explode("=", $pair);
            if (count($ex) < 2) {
                continue;
            }
            list($k, $v) = array_map("urldecode", $ex);
            $op[$k] = $v;
        }

        return $op;
    }


    /**
     * If parameters are real null, skip class init
     */
    public function initData(
        ?string $filterData = null,
        ?string $sortData = null,
        ?int $page = null
    ): void {
        if ($filterData !== null) {
            $this->filter = new Filters($this, "{$this->urlPrefix}%filter/{$sortData}", $filterData);
        }
        if ($sortData !== null) {
            $this->sort = new Sort($this, "{$this->urlPrefix}{$filterData}/%sort", $sortData);
        }
        if ($page !== null) {
            $this->pagination = new Pagination($this, "{$this->urlPrefix}{$filterData}/{$sortData}/%pagination", $page);
        }
    }


    public function getColumns(): array
    {
        return $this->columns;
    }

    public function setColumns(array $columns): void
    {
        foreach ($columns as $column) {
            if ($column instanceof Column == false) {
                throw new \Exception("Not all columns are instances of Column");
            }

            $this->columns[$column->id] = $column;
        }
    }


    public function getRows(): array
    {
        return $this->rows;
    }

    public function setRows(array &$rows): void
    {
        $this->rows = &$rows;

        // Format row values
        if ($this->initRow !== null) {
            foreach ($this->rows as $rowIndex => $row) {
                $this->rows[$rowIndex] = Utils::expandClosure($this->initRow, [$rowIndex, $row]);
            }
        }

        // Format column values
        foreach ($this->columns as $column) {
            if ($column->initValue !== null) {
                foreach ($this->rows as $rowIndex => $row) {
                    $this->rows[$rowIndex][$column->id] = Utils::expandClosure(
                        $column->initValue,
                        [$column, $rowIndex, $row]
                    );
                }
            }
        }
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function setChildren(array &$children): void
    {
        $this->children = &$children;
    }

    public function makeOutput()
    {
        if (!empty($this->outputGenerator)) {
            return $this->outputGenerator->makeOutput();
        }
    }

    public function showOutput(): void
    {
        if (!empty($this->outputGenerator)) {
            $this->outputGenerator->showOutput();
        }
    }
}
