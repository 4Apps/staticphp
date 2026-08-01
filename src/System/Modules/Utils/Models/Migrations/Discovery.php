<?php

namespace System\Modules\Utils\Models\Migrations;

/**
 * Reads migration files off disk and validates them before anything touches a database.
 */
class Discovery
{
    /**
     * Filenames must sort chronologically as plain text - that is the whole point of the
     * timestamp prefix, and it is what lets `sort()` below stand in for an ordering column.
     *
     * @var string
     * @access public
     */
    public const FILENAME_PATTERN = '/^(\d{4}-\d{2}-\d{2}-\d{6})-([a-z0-9-]+)\.sql$/';

    /**
     * Opts a file out of transaction wrapping. Must be the very first line.
     *
     * @var string
     * @access public
     */
    public const NO_TRANSACTION_DIRECTIVE = '-- migrations:no-transaction';

    /**
     * Compute the checksum of a migration's contents.
     *
     * Over the raw bytes, so that a change to a comment or to line endings is drift too.
     * That is deliberate: the checksum answers "is this the file that ran?", not "would
     * this file do the same thing?", and only the first question is answerable.
     *
     * @access public
     * @static
     * @param  string $data
     * @return string
     */
    public static function checksum(string $data): string
    {
        return hash('sha256', $data);
    }

    /**
     * Load and validate one migration file.
     *
     * @access public
     * @static
     * @param  string $path
     * @return MigrationFile
     */
    public static function load(string $path): MigrationFile
    {
        $name = basename($path);
        if (preg_match(self::FILENAME_PATTERN, $name, $matches) !== 1) {
            throw new MigrationError(
                "Bad migration filename \"{$name}\": expected YYYY-MM-DD-HHMMSS-kebab-name.sql"
            );
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new MigrationError("Cannot read migration file: {$path}");
        }

        // A file that is not valid UTF-8 will be rejected by the server, or worse, stored
        // mangled. Catching it here names the file instead of surfacing a driver error.
        if (mb_check_encoding($raw, 'UTF-8') === false) {
            throw new MigrationError("Migration \"{$name}\" is not valid UTF-8");
        }

        $firstLine = trim(strtok($raw, "\n") ?: '');

        return new MigrationFile(
            name: $name,
            prefix: $matches[1],
            path: $path,
            sql: $raw,
            checksum: self::checksum($raw),
            noTransaction: $firstLine === self::NO_TRANSACTION_DIRECTIVE
        );
    }

    /**
     * Every *.sql in $directory, in chronological order.
     *
     * @access public
     * @static
     * @param  string $directory
     * @return MigrationFile[]
     */
    public static function discover(string $directory): array
    {
        if (is_dir($directory) === false) {
            throw new MigrationError("Migrations directory does not exist: {$directory}");
        }

        $paths = glob(rtrim($directory, '/') . '/*.sql');
        if ($paths === false) {
            throw new MigrationError("Cannot list migrations directory: {$directory}");
        }

        sort($paths, SORT_STRING);

        $migrations = [];
        $seen = [];
        foreach ($paths as $path) {
            $migration = self::load($path);

            // Two files sharing a timestamp have no defined order between them, so the
            // sequence would depend on the rest of the filename - which is how two
            // developers branching on the same afternoon get different orderings on
            // different machines.
            if (isset($seen[$migration->prefix])) {
                throw new MigrationError(
                    "Duplicate migration timestamp {$migration->prefix}: "
                    . "{$seen[$migration->prefix]} and {$migration->name}"
                );
            }

            $seen[$migration->prefix] = $migration->name;
            $migrations[] = $migration;
        }

        return $migrations;
    }

    /**
     * 1-indexed line numbers of psql meta-commands.
     *
     * PDO sends SQL straight to the server - there is no psql to interpret a backslash
     * command, so they arrive as syntax errors. pg_dump emits \restrict and \unrestrict,
     * which is how they end up in a migration in the first place.
     *
     * Only a line that starts with a backslash counts; one inside a string literal is not
     * a meta-command.
     *
     * @access public
     * @static
     * @param  string $sql
     * @return array<int, string> Line number => line
     */
    public static function findMetaCommands(string $sql): array
    {
        $found = [];
        foreach (explode("\n", $sql) as $index => $line) {
            if (preg_match('/^\s*\\\\/', $line) === 1) {
                $found[$index + 1] = rtrim($line);
            }
        }

        return $found;
    }

    /**
     * Crude statement count: strip comments, split on ';', count non-empty remainders.
     *
     * A semicolon inside a dollar-quoted block or a string literal counts as a separator
     * here, so such a file is over-counted. That is acceptable because the only caller
     * uses this to refuse a multi-statement no-transaction file before anything runs - the
     * false positive is a loud refusal at scan time, and the operations the directive
     * exists for (CREATE INDEX CONCURRENTLY, sqlite table rebuilds) are never
     * dollar-quoted.
     *
     * @access public
     * @static
     * @param  string $sql
     * @return int
     */
    public static function countStatements(string $sql): int
    {
        $stripped = preg_replace('/--[^\n]*/', '', $sql) ?? $sql;

        $count = 0;
        foreach (explode(';', $stripped) as $part) {
            if (trim($part) !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Build a filename for a new migration.
     *
     * @access public
     * @static
     * @param  string $name Free text, slugified
     * @param  int    $now  Unix timestamp
     * @return string
     */
    public static function newFilename(string $name, int $now): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
        if ($slug === '') {
            throw new MigrationError("Migration name \"{$name}\" contains no usable characters");
        }

        return gmdate('Y-m-d-His', $now) . "-{$slug}.sql";
    }
}
