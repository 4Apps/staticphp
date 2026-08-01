<?php

namespace System\Modules\Utils\Models\Migrations;

/**
 * One migration, paired with whatever the tracking table knows about it.
 */
class MigrationState
{
    /**
     * @access public
     * @param string         $name  Migration filename
     * @param State          $state
     * @param ?MigrationFile $file  Null when the file is gone (MISSING)
     * @param ?AppliedRow    $row   Null when it has never run (PENDING)
     */
    public function __construct(
        public readonly string $name,
        public readonly State $state,
        public readonly ?MigrationFile $file,
        public readonly ?AppliedRow $row
    ) {
    }
}
