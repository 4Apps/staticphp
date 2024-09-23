<?php

namespace System\Modules\Presentation\Models\Tables\SQL;

use System\Modules\Presentation\Models\Tables\Table;

class SQLTable extends Table
{
    public ?SQLSort $sqlSort = null;
    public ?SQLFilters $sqlFilter = null;
    public ?SQLPagination $sqlPagination = null;

    /**
     * If parameters are real null, skip class init
     */
    public function initData(?string $filterData = null, ?string $sortData = null, ?int $page = null): void
    {
        parent::initData($filterData, $sortData, $page);

        if ($filterData !== null) {
            $this->sqlFilter = new SQLFilters($this);
        }
        if ($sortData !== null) {
            $this->sqlSort = new SQLSort($this);
        }
        if ($page !== null) {
            $this->sqlPagination = new SQLPagination($this);
        }
    }
}
