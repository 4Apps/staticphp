<?php

namespace System\Modules\Utils\Models\Migrations;

/**
 * One migration on disk.
 *
 * Readonly properties rather than a readonly class, because the framework's floor is
 * PHP 8.1 and readonly classes arrived in 8.2.
 */
class MigrationFile
{
    /**
     * @access public
     * @param string $name          Bare filename, e.g. 2026-08-01-143000-add-users.sql
     * @param string $prefix        The timestamp part, used for ordering and --to
     * @param string $path          Absolute path
     * @param string $sql           File contents
     * @param string $checksum      sha256 of the raw bytes
     * @param bool   $noTransaction Whether the file opted out of transaction wrapping
     */
    public function __construct(
        public readonly string $name,
        public readonly string $prefix,
        public readonly string $path,
        public readonly string $sql,
        public readonly string $checksum,
        public readonly bool $noTransaction
    ) {
    }
}
