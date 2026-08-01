<?php

namespace System\Modules\Utils\Models\Migrations;

/**
 * Turns "what is on disk" plus "what the table says" into one ordered list of states.
 *
 * Pure functions over plain values - no database, no filesystem - so the whole state
 * machine is testable without either.
 */
class States
{
    /**
     * Union of disk and table, ordered by name.
     *
     * A row with no file (MISSING) sorts in among the files rather than being appended, so
     * a `status` listing reads chronologically whatever went wrong.
     *
     * @access public
     * @static
     * @param  MigrationFile[] $files
     * @param  AppliedRow[]    $rows
     * @return MigrationState[]
     */
    public static function compute(array $files, array $rows): array
    {
        $byName = [];
        foreach ($files as $file) {
            $byName[$file->name] = $file;
        }

        $rowsByName = [];
        foreach ($rows as $row) {
            $rowsByName[$row->name] = $row;
        }

        $names = array_keys($byName + $rowsByName);
        sort($names, SORT_STRING);

        $states = [];
        foreach ($names as $name) {
            $file = $byName[$name] ?? null;
            $row = $rowsByName[$name] ?? null;

            if ($row !== null && $row->durationMs === null) {
                // Checked before DRIFT and MISSING: a half-applied migration is the more
                // urgent fact, and re-stamping its checksum would bury it.
                $state = State::FAILED;
            } elseif ($file === null) {
                $state = State::MISSING;
            } elseif ($row === null) {
                $state = State::PENDING;
            } elseif ($row->checksum !== $file->checksum) {
                $state = State::DRIFT;
            } else {
                $state = State::APPLIED;
            }

            $states[] = new MigrationState(name: $name, state: $state, file: $file, row: $row);
        }

        return $states;
    }

    /**
     * The migrations waiting to run.
     *
     * @access public
     * @static
     * @param  MigrationState[] $states
     * @return MigrationState[]
     */
    public static function pending(array $states): array
    {
        return array_values(
            array_filter($states, fn(MigrationState $state) => $state->state === State::PENDING)
        );
    }

    /**
     * States that must be resolved before anything may be applied.
     *
     * @access public
     * @static
     * @param  MigrationState[] $states
     * @return MigrationState[]
     */
    public static function blocking(array $states): array
    {
        return array_values(
            array_filter(
                $states,
                fn(MigrationState $state) => in_array(
                    $state->state,
                    [State::DRIFT, State::MISSING, State::FAILED],
                    true
                )
            )
        );
    }
}
