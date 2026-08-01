<?php

namespace System\Modules\Utils\Models\Migrations;

/**
 * Where one migration stands, as the union of what is on disk and what the tracking table
 * says.
 *
 * Only APPLIED and PENDING are ordinary. The other three are all "stop and look", and are
 * spelled in capitals so they stand out in a `status` listing.
 */
enum State: string
{
    /** On disk, recorded, checksums agree. */
    case APPLIED = 'applied';

    /** On disk, not recorded. Waiting to run. */
    case PENDING = 'PENDING';

    /** On disk and recorded, but the file changed after it was applied. */
    case DRIFT = 'DRIFT';

    /** Recorded, but the file is gone. */
    case MISSING = 'MISSING';

    /**
     * Recorded as started and never confirmed - the migration ran, then the process died
     * or the statement failed, on a database that could not roll the DDL back.
     */
    case FAILED = 'FAILED';
}
