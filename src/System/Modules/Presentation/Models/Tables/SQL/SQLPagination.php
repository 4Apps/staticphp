<?php

namespace System\Modules\Presentation\Models\Tables\SQL;

use System\Modules\Presentation\Models\Tables\Interfaces\TableInstanceInterface;
use System\Modules\Presentation\Models\Tables\Traits\TableInstance;

/**
 * Paging SQL Implementation
 */
class SQLPagination implements TableInstanceInterface
{
    use TableInstance;

    public function limitQuery()
    {
        return <<<EOL
OFFSET {$this->tableInstance->pagination->limitFrom}
LIMIT {$this->tableInstance->pagination->limitPerPage}
EOL;
    }
}
