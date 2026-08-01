<?php

namespace System\Tests\Modules\Utils\Models\Migrations;

use PHPUnit\Framework\TestCase;
use System\Modules\Utils\Models\Migrations\AppliedRow;
use System\Modules\Utils\Models\Migrations\MigrationFile;
use System\Modules\Utils\Models\Migrations\State;
use System\Modules\Utils\Models\Migrations\States;

class StatesTest extends TestCase
{
    private function file(string $name, string $checksum = 'aaa'): MigrationFile
    {
        return new MigrationFile(
            name: $name,
            prefix: substr($name, 0, 17),
            path: "/tmp/{$name}",
            sql: 'SELECT 1;',
            checksum: $checksum,
            noTransaction: false
        );
    }

    private function row(string $name, string $checksum = 'aaa', ?int $durationMs = 5): AppliedRow
    {
        return new AppliedRow(
            name: $name,
            checksum: $checksum,
            appliedAt: '2026-08-01 14:30:00',
            durationMs: $durationMs
        );
    }

    private function stateFor(array $states, string $name): State
    {
        foreach ($states as $state) {
            if ($state->name === $name) {
                return $state->state;
            }
        }

        $this->fail("No state computed for {$name}");
    }

    /*
    | The four ordinary combinations of disk and table
    */

    public function testFileAndMatchingRowIsApplied()
    {
        $states = States::compute([$this->file('2026-08-01-143000-a.sql')], [$this->row('2026-08-01-143000-a.sql')]);

        $this->assertSame(State::APPLIED, $this->stateFor($states, '2026-08-01-143000-a.sql'));
    }

    public function testFileWithNoRowIsPending()
    {
        $states = States::compute([$this->file('2026-08-01-143000-a.sql')], []);

        $this->assertSame(State::PENDING, $this->stateFor($states, '2026-08-01-143000-a.sql'));
    }

    public function testChangedFileIsDrift()
    {
        $states = States::compute(
            [$this->file('2026-08-01-143000-a.sql', 'new')],
            [$this->row('2026-08-01-143000-a.sql', 'old')]
        );

        $this->assertSame(State::DRIFT, $this->stateFor($states, '2026-08-01-143000-a.sql'));
    }

    public function testRowWithNoFileIsMissing()
    {
        $states = States::compute([], [$this->row('2026-08-01-143000-a.sql')]);

        $this->assertSame(State::MISSING, $this->stateFor($states, '2026-08-01-143000-a.sql'));
    }

    /*
    | FAILED
    |
    | A null duration means the migration was claimed and never confirmed. It outranks
    | DRIFT and MISSING because a half-applied schema is the more urgent fact, and
    | re-stamping its checksum would bury it.
    */

    public function testRowWithNoDurationIsFailed()
    {
        $states = States::compute(
            [$this->file('2026-08-01-143000-a.sql')],
            [$this->row('2026-08-01-143000-a.sql', 'aaa', null)]
        );

        $this->assertSame(State::FAILED, $this->stateFor($states, '2026-08-01-143000-a.sql'));
    }

    public function testFailedOutranksDrift()
    {
        $states = States::compute(
            [$this->file('2026-08-01-143000-a.sql', 'new')],
            [$this->row('2026-08-01-143000-a.sql', 'old', null)]
        );

        $this->assertSame(State::FAILED, $this->stateFor($states, '2026-08-01-143000-a.sql'));
    }

    public function testFailedOutranksMissing()
    {
        $states = States::compute([], [$this->row('2026-08-01-143000-a.sql', 'aaa', null)]);

        $this->assertSame(State::FAILED, $this->stateFor($states, '2026-08-01-143000-a.sql'));
    }

    /*
    | Ordering and grouping
    */

    public function testAMissingRowSortsInAmongTheFilesRatherThanBeingAppended()
    {
        $states = States::compute(
            [
                $this->file('2026-08-01-100000-a.sql'),
                $this->file('2026-08-03-100000-c.sql'),
            ],
            [$this->row('2026-08-02-100000-b.sql')]
        );

        $this->assertSame(
            [
                '2026-08-01-100000-a.sql',
                '2026-08-02-100000-b.sql',
                '2026-08-03-100000-c.sql',
            ],
            array_map(fn($state) => $state->name, $states)
        );
    }

    public function testPendingReturnsOnlyPending()
    {
        $states = States::compute(
            [
                $this->file('2026-08-01-100000-a.sql'),
                $this->file('2026-08-02-100000-b.sql'),
            ],
            [$this->row('2026-08-01-100000-a.sql')]
        );

        $pending = States::pending($states);

        $this->assertCount(1, $pending);
        $this->assertSame('2026-08-02-100000-b.sql', $pending[0]->name);
    }

    public function testBlockingCoversDriftMissingAndFailed()
    {
        $states = States::compute(
            [
                $this->file('2026-08-01-100000-a.sql', 'new'),
                $this->file('2026-08-03-100000-c.sql'),
                $this->file('2026-08-04-100000-d.sql'),
            ],
            [
                $this->row('2026-08-01-100000-a.sql', 'old'),
                $this->row('2026-08-02-100000-b.sql'),
                $this->row('2026-08-04-100000-d.sql', 'aaa', null),
            ]
        );

        $blocked = array_map(fn($state) => $state->name, States::blocking($states));

        $this->assertSame(
            [
                '2026-08-01-100000-a.sql',
                '2026-08-02-100000-b.sql',
                '2026-08-04-100000-d.sql',
            ],
            $blocked
        );
    }

    public function testAnEmptyUniverseProducesNoStates()
    {
        $this->assertSame([], States::compute([], []));
    }
}
