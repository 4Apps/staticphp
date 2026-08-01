<?php

namespace System\Tests\Modules\Utils\Models\Migrations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use System\Modules\Utils\Models\Migrations\Discovery;
use System\Modules\Utils\Models\Migrations\MigrationError;

class DiscoveryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sp_discovery_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function write(string $name, string $contents = "SELECT 1;\n"): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    /*
    | Filenames
    */

    public function testAWellFormedFilenameIsAccepted()
    {
        $file = Discovery::load($this->write('2026-08-01-143000-add-users.sql'));

        $this->assertSame('2026-08-01-143000-add-users.sql', $file->name);
        $this->assertSame('2026-08-01-143000', $file->prefix);
    }

    #[DataProvider('badFilenameProvider')]
    public function testBadFilenamesAreRejected($name)
    {
        $this->expectException(MigrationError::class);
        Discovery::load($this->write($name));
    }

    public static function badFilenameProvider(): array
    {
        return [
            'no timestamp'      => ['add-users.sql'],
            'wrong separator'   => ['2026_08_01_143000-add-users.sql'],
            'short time'        => ['2026-08-01-1430-add-users.sql'],
            'uppercase slug'    => ['2026-08-01-143000-addUsers.sql'],
            'underscore slug'   => ['2026-08-01-143000-add_users.sql'],
            'no slug'           => ['2026-08-01-143000.sql'],
            'wrong extension'   => ['2026-08-01-143000-add-users.txt'],
        ];
    }

    public function testDuplicateTimestampsAreRejected()
    {
        $this->write('2026-08-01-143000-add-users.sql');
        $this->write('2026-08-01-143000-add-posts.sql');

        $this->expectException(MigrationError::class);
        $this->expectExceptionMessageMatches('/Duplicate migration timestamp/');
        Discovery::discover($this->dir);
    }

    public function testDiscoveryReturnsFilesInChronologicalOrder()
    {
        $this->write('2026-08-02-090000-third.sql');
        $this->write('2026-08-01-143000-first.sql');
        $this->write('2026-08-01-150000-second.sql');

        $names = array_map(fn($file) => $file->name, Discovery::discover($this->dir));

        $this->assertSame(
            [
                '2026-08-01-143000-first.sql',
                '2026-08-01-150000-second.sql',
                '2026-08-02-090000-third.sql',
            ],
            $names
        );
    }

    public function testAMissingDirectoryIsAnError()
    {
        $this->expectException(MigrationError::class);
        Discovery::discover($this->dir . '/nope');
    }

    /*
    | Checksums
    |
    | Over the raw bytes, so a comment-only edit is drift too - the checksum answers
    | "is this the file that ran", not "would this file do the same thing".
    */

    public function testChecksumCoversCommentsAndWhitespace()
    {
        $a = Discovery::load($this->write('2026-08-01-143000-a.sql', "SELECT 1;\n"));
        $b = Discovery::load($this->write('2026-08-01-150000-b.sql', "-- note\nSELECT 1;\n"));

        $this->assertNotSame($a->checksum, $b->checksum);
    }

    public function testChecksumIsSha256OfTheRawBytes()
    {
        $file = Discovery::load($this->write('2026-08-01-143000-a.sql', 'SELECT 1;'));

        $this->assertSame(hash('sha256', 'SELECT 1;'), $file->checksum);
    }

    public function testInvalidUtf8IsRejected()
    {
        $this->expectException(MigrationError::class);
        $this->expectExceptionMessageMatches('/not valid UTF-8/');
        Discovery::load($this->write('2026-08-01-143000-a.sql', "SELECT '\xC3\x28';"));
    }

    /*
    | Directives
    */

    public function testNoTransactionDirectiveIsReadFromTheFirstLine()
    {
        $file = Discovery::load($this->write(
            '2026-08-01-143000-a.sql',
            "-- migrations:no-transaction\nCREATE INDEX CONCURRENTLY x ON y (z);\n"
        ));

        $this->assertTrue($file->noTransaction);
    }

    public function testTheDirectiveIsIgnoredBelowTheFirstLine()
    {
        $file = Discovery::load($this->write(
            '2026-08-01-143000-a.sql',
            "-- a comment\n-- migrations:no-transaction\nSELECT 1;\n"
        ));

        $this->assertFalse($file->noTransaction);
    }

    /*
    | Meta commands
    |
    | pg_dump emits \restrict and \unrestrict. PDO sends SQL straight to the server, so
    | there is no psql to interpret them and they arrive as syntax errors.
    */

    public function testMetaCommandsAreFoundWithTheirLineNumbers()
    {
        $found = Discovery::findMetaCommands("SELECT 1;\n\\restrict abc\nSELECT 2;\n");

        $this->assertSame([2 => '\\restrict abc'], $found);
    }

    public function testABackslashInsideAStringIsNotAMetaCommand()
    {
        $this->assertSame([], Discovery::findMetaCommands("SELECT 'a\\b';\n"));
    }

    /*
    | Statement counting
    */

    public function testStatementsAreCounted()
    {
        $this->assertSame(1, Discovery::countStatements('SELECT 1;'));
        $this->assertSame(2, Discovery::countStatements('SELECT 1; SELECT 2;'));
        $this->assertSame(1, Discovery::countStatements("-- SELECT 9; a comment\nSELECT 1;"));
        $this->assertSame(0, Discovery::countStatements("-- only a comment\n"));
    }

    /*
    | New filenames
    */

    public function testNewFilenameSlugifies()
    {
        $this->assertSame(
            '2026-08-01-143000-add-users-table.sql',
            Discovery::newFilename('Add Users Table!', gmmktime(14, 30, 0, 8, 1, 2026))
        );
    }

    public function testNewFilenameRejectsANameWithNothingUsable()
    {
        $this->expectException(MigrationError::class);
        Discovery::newFilename('!!!', gmmktime(14, 30, 0, 8, 1, 2026));
    }

    public function testANewFilenameIsAcceptedByTheLoader()
    {
        $name = Discovery::newFilename('add users', gmmktime(14, 30, 0, 8, 1, 2026));

        $this->assertSame($name, Discovery::load($this->write($name))->name);
    }
}
