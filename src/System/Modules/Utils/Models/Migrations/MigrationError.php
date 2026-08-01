<?php

namespace System\Modules\Utils\Models\Migrations;

/**
 * Anything wrong with the migration files themselves, as opposed to the SQL failing.
 *
 * Kept distinct from the PDOException a failing migration throws, because the two need
 * different advice: a bad filename is fixed in the editor, a failing statement is fixed in
 * the database.
 */
class MigrationError extends \Exception
{
}
