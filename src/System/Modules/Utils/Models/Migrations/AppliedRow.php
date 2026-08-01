<?php

namespace System\Modules\Utils\Models\Migrations;

/**
 * One row of the tracking table.
 *
 * A null $durationMs means the migration was claimed but never confirmed - see
 * Tracker::claim(). On a database with transactional DDL that state is unreachable,
 * because the row and the migration commit together.
 */
class AppliedRow
{
    /**
     * @access public
     * @param string  $name       Migration filename
     * @param string  $checksum   sha256 recorded when it was applied
     * @param string  $appliedAt  Timestamp as the driver returned it
     * @param ?int    $durationMs Null when the migration never completed
     */
    public function __construct(
        public readonly string $name,
        public readonly string $checksum,
        public readonly string $appliedAt,
        public readonly ?int $durationMs
    ) {
    }
}
