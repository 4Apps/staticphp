<?php

namespace System\Modules\Utils\Models\Migrations;

use PDO;

/**
 * The migration commands, driver agnostic.
 *
 * Every command returns a process exit code, and writes through the $out callable rather
 * than echoing, so the whole surface is testable without capturing output buffers.
 */
class Commands
{
    private PDO $pdo;
    private Tracker $tracker;
    private string $directory;

    /** @var callable */
    private $out;

    /**
     * @access public
     * @param PDO      $pdo
     * @param Tracker  $tracker
     * @param string   $directory Where the .sql files live
     * @param callable $out       Receives one line at a time, without a trailing newline
     */
    public function __construct(PDO $pdo, Tracker $tracker, string $directory, callable $out)
    {
        $this->pdo = $pdo;
        $this->tracker = $tracker;
        $this->directory = $directory;
        $this->out = $out;
    }

    /**
     * @access private
     * @param  string $line
     * @return void
     */
    private function say(string $line = ''): void
    {
        ($this->out)($line);
    }

    /**
     * @access private
     * @return MigrationState[]
     */
    private function loadStates(): array
    {
        $this->tracker->ensureTable();

        return States::compute(Discovery::discover($this->directory), $this->tracker->appliedRows());
    }

    /**
     * List every migration and what it is doing.
     *
     * @access public
     * @param  bool $check Exit non-zero when anything is pending or blocked, for CI
     * @return int
     */
    public function status(bool $check = false): int
    {
        try {
            $states = $this->loadStates();
        } catch (MigrationError $e) {
            $this->say("error: {$e->getMessage()}");

            return 1;
        }

        if ($states === []) {
            $this->say('No migrations found.');

            return 0;
        }

        foreach ($states as $state) {
            $appliedAt = $state->row === null ? '' : $state->row->appliedAt;
            $this->say(sprintf('  %-8s %-52s %s', $state->state->value, $state->name, $appliedAt));
        }

        $blocked = States::blocking($states);
        $waiting = States::pending($states);
        $applied = count($states) - count($waiting) - count($blocked);

        $this->say();
        $this->say(sprintf('%d applied, %d pending, %d blocked', $applied, count($waiting), count($blocked)));

        if ($blocked !== []) {
            $this->say();
            $this->reportBlocking($blocked);
        }

        if ($check === true && ($waiting !== [] || $blocked !== [])) {
            return 1;
        }

        return 0;
    }

    /**
     * Explain each blocking state, and what resolves it.
     *
     * The states need different advice: `repair` re-reads the file to re-stamp its
     * checksum, so it is the DRIFT remedy and is useless for MISSING - pointing an operator
     * at it there just earns them "no such migration file".
     *
     * @access private
     * @param  MigrationState[] $blocked
     * @return void
     */
    private function reportBlocking(array $blocked): void
    {
        foreach ($blocked as $state) {
            $reason = match ($state->state) {
                State::DRIFT => 'file changed after it was applied',
                State::MISSING => 'applied, but the file is gone',
                default => 'started and never finished - the database may be half-migrated',
            };

            $this->say("  {$state->state->value} {$state->name}  ({$reason})");
        }

        $this->say();

        foreach ($blocked as $state) {
            switch ($state->state) {
                case State::DRIFT:
                    $this->say(
                        "DRIFT {$state->name}: revert the edit, or run "
                        . "`staticphp migrate repair {$state->name}` if the edit was deliberate."
                    );
                    break;

                case State::MISSING:
                    $this->say(
                        "MISSING {$state->name}: restore the file from version control. If it is gone for"
                    );
                    $this->say(
                        'good and its schema change is known to be in place, run '
                        . "`staticphp migrate forget {$state->name}`."
                    );
                    break;

                case State::FAILED:
                    $this->say(
                        "FAILED {$state->name}: this migration started and never confirmed, so part of it"
                    );
                    $this->say(
                        'may have landed. Inspect the database, undo or complete whatever it did, then run'
                    );
                    $this->say(
                        "`staticphp migrate forget {$state->name}` to retry it, or "
                        . '`staticphp migrate baseline` to accept it as done.'
                    );
                    break;

                default:
                    break;
            }
        }
    }

    /**
     * Apply every pending migration, in order.
     *
     * @access public
     * @param  bool    $dryRun
     * @param  ?string $to        Stop after the migration with this timestamp prefix
     * @param  string  $appliedBy Recorded against each row
     * @return int
     */
    public function apply(bool $dryRun, ?string $to, string $appliedBy): int
    {
        return $this->tracker->withLock(function () use ($dryRun, $to, $appliedBy) {
            try {
                $states = $this->loadStates();
            } catch (MigrationError $e) {
                $this->say("error: {$e->getMessage()}");

                return 1;
            }

            $blocked = States::blocking($states);
            if ($blocked !== []) {
                $this->reportBlocking($blocked);

                return 1;
            }

            $queue = States::pending($states);
            if ($to !== null) {
                $known = [];
                foreach ($states as $state) {
                    if ($state->file !== null) {
                        $known[$state->file->prefix] = true;
                    }
                }

                if (isset($known[$to]) === false) {
                    $this->say("error: no migration with prefix \"{$to}\"");

                    return 1;
                }

                $queue = array_values(
                    array_filter($queue, fn(MigrationState $state) => $state->file->prefix <= $to)
                );
            }

            if ($queue === []) {
                $this->say('Database is up to date; nothing to apply.');

                return 0;
            }

            // Validate the whole queue before executing any of it, so a problem in the last
            // file is found before the first one has changed the database.
            $error = $this->validateQueue($queue);
            if ($error !== null) {
                $this->say($error);

                return 1;
            }

            foreach ($queue as $state) {
                if ($dryRun === true) {
                    $this->say("would apply {$state->name}");
                    continue;
                }

                $code = $this->runOne($state, $appliedBy);
                if ($code !== 0) {
                    return $code;
                }
            }

            if ($dryRun === true) {
                $this->say(count($queue) . ' migration(s) would be applied; nothing was changed.');
            } else {
                $this->say('Applied ' . count($queue) . ' migration(s).');
            }

            return 0;
        });
    }

    /**
     * @access private
     * @param  MigrationState[] $queue
     * @return ?string Error message, or null when the queue is fine
     */
    private function validateQueue(array $queue): ?string
    {
        foreach ($queue as $state) {
            $file = $state->file;

            $meta = Discovery::findMetaCommands($file->sql);
            if ($meta !== []) {
                $lines = [];
                foreach ($meta as $number => $line) {
                    $lines[] = "    line {$number}: {$line}";
                }

                return "error: {$state->name} contains psql meta-command(s) PDO cannot execute:\n"
                    . implode("\n", $lines)
                    . "\nStrip them (pg_dump emits \\restrict / \\unrestrict) and try again.";
            }

            if ($file->noTransaction === true) {
                $problem = $this->tracker->driver()->noTransactionFileError($file);
                if ($problem !== null) {
                    return "error: {$problem}";
                }
            }
        }

        return null;
    }

    /**
     * Run one migration and record it.
     *
     * @access private
     * @param  MigrationState $state
     * @param  string         $appliedBy
     * @return int
     */
    private function runOne(MigrationState $state, string $appliedBy): int
    {
        $file = $state->file;
        $transactional = $this->tracker->driver()->supportsTransactionalDdl() && $file->noTransaction === false;
        $started = microtime(true);

        if ($transactional === true) {
            try {
                $this->pdo->beginTransaction();
                $this->pdo->exec($file->sql);

                // The tracking row is written inside the same transaction as the migration,
                // so the file either fully lands and is recorded, or neither.
                $duration = (int) round((microtime(true) - $started) * 1000);
                $this->tracker->record($file->name, $file->checksum, $duration, $appliedBy);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction() === true) {
                    $this->pdo->rollBack();
                }

                $this->say("FAILED {$state->name}: {$e->getMessage()}");
                $this->say('Rolled back. Nothing from this migration, or after it, was applied.');

                return 1;
            }

            $this->say("applied {$state->name} ({$duration} ms)");

            return 0;
        }

        // No usable transaction: claim the migration first, so that a failure or a hard
        // kill leaves a row with no duration - reported as FAILED and refused by the next
        // apply - rather than a PENDING file that has already half-run.
        try {
            $this->tracker->claim($file->name, $file->checksum, $appliedBy);
        } catch (\Throwable $e) {
            $this->say("FAILED {$state->name}: could not record the migration before running it: "
                . $e->getMessage());

            return 1;
        }

        try {
            $this->pdo->exec($file->sql);
        } catch (\Throwable $e) {
            $this->say("FAILED {$state->name}: {$e->getMessage()}");
            $this->say('This migration ran OUTSIDE a transaction, so it may have PARTIALLY applied.');
            $this->say('It is recorded as FAILED and no later migration will run until you resolve it.');
            $this->say('Inspect the database, then either finish the change by hand and run');
            $this->say('`staticphp migrate baseline`, or undo it and run');
            $this->say("`staticphp migrate forget {$state->name}` to retry.");

            return 1;
        }

        $duration = (int) round((microtime(true) - $started) * 1000);
        $this->tracker->confirm($file->name, $duration);
        $this->say("applied {$state->name} ({$duration} ms, no transaction)");

        return 0;
    }

    /**
     * Write tracking rows without executing anything.
     *
     * The adoption path for a database that already has the schema. Nothing is written
     * until every answer is in, so quitting really does leave the table untouched.
     *
     * @access public
     * @param  ?string  $to
     * @param  bool     $assumeYes
     * @param  string   $appliedBy
     * @param  callable $prompt Receives a question, returns the answer
     * @return int
     */
    public function baseline(?string $to, bool $assumeYes, string $appliedBy, callable $prompt): int
    {
        return $this->tracker->withLock(function () use ($to, $assumeYes, $appliedBy, $prompt) {
            try {
                $states = $this->loadStates();
            } catch (MigrationError $e) {
                $this->say("error: {$e->getMessage()}");

                return 1;
            }

            // Unlike apply, this deliberately runs without a blocking() guard: accepting a
            // FAILED migration as done is one of the ways out of that state.
            $candidates = States::pending($states);
            foreach ($states as $state) {
                if ($state->state === State::FAILED) {
                    $candidates[] = $state;
                }
            }

            usort($candidates, fn(MigrationState $a, MigrationState $b) => strcmp($a->name, $b->name));

            if ($to !== null) {
                $known = [];
                foreach ($states as $state) {
                    if ($state->file !== null) {
                        $known[$state->file->prefix] = true;
                    }
                }

                if (isset($known[$to]) === false) {
                    $this->say("error: no migration with prefix \"{$to}\"");

                    return 1;
                }

                $candidates = array_values(
                    array_filter(
                        $candidates,
                        fn(MigrationState $state) => $state->file !== null && $state->file->prefix <= $to
                    )
                );
            }

            if ($candidates === []) {
                $this->say('Nothing to baseline; every migration on disk is already recorded.');

                return 0;
            }

            $chosen = [];
            $stampRest = $assumeYes;
            foreach ($candidates as $state) {
                if ($stampRest === true) {
                    $chosen[] = $state;
                    continue;
                }

                $answer = strtolower(trim((string) $prompt(
                    sprintf('  %-52s mark as already applied? [y/N/a/q] ', $state->name)
                )));

                if ($answer === 'q') {
                    $this->say('Aborted; nothing was written.');

                    return 1;
                }

                if ($answer === 'a') {
                    $stampRest = true;
                    $chosen[] = $state;
                } elseif ($answer === 'y') {
                    $chosen[] = $state;
                }
            }

            try {
                foreach ($chosen as $state) {
                    // A FAILED migration already has a row; replace it rather than
                    // inserting a duplicate that would trip the unique index.
                    if ($state->row !== null) {
                        $this->tracker->forget($state->name);
                    }

                    $this->tracker->record($state->name, $state->file->checksum, 0, $appliedBy);
                }
            } catch (\Throwable $e) {
                $this->say("error: failed to write tracking rows: {$e->getMessage()}");

                return 1;
            }

            $this->say();
            $this->say(sprintf(
                'stamped %d migration(s) as applied (not executed); %d left pending',
                count($chosen),
                count($candidates) - count($chosen)
            ));

            return 0;
        });
    }

    /**
     * Create an empty migration file.
     *
     * @access public
     * @param  string $name
     * @param  int    $now Unix timestamp
     * @return int
     */
    public function create(string $name, int $now): int
    {
        try {
            $filename = Discovery::newFilename($name, $now);
        } catch (MigrationError $e) {
            $this->say("error: {$e->getMessage()}");

            return 1;
        }

        if (is_dir($this->directory) === false) {
            $this->say("error: migrations directory does not exist: {$this->directory}");

            return 1;
        }

        $path = rtrim($this->directory, '/') . '/' . $filename;
        if (file_exists($path) === true) {
            $this->say("error: {$path} already exists");

            return 1;
        }

        $template = "-- {$name}\n"
            . "--\n"
            . "-- Runs in a transaction where the database supports one for DDL (postgres, sqlite).\n"
            . "-- MySQL commits DDL implicitly, so there a failure part-way leaves the change half\n"
            . "-- applied - keep MySQL migrations to a single statement where you can.\n"
            . "--\n"
            . "-- Add `" . Discovery::NO_TRANSACTION_DIRECTIVE . "` as the very first line if this file\n"
            . "-- needs CREATE INDEX CONCURRENTLY, a sqlite table rebuild, or anything else that must\n"
            . "-- not run inside a transaction.\n";

        if (file_put_contents($path, $template) === false) {
            $this->say("error: could not write {$path}");

            return 1;
        }

        $this->say("created {$path}");

        return 0;
    }

    /**
     * Re-stamp one migration's checksum after a deliberate edit.
     *
     * The only way out of DRIFT short of reverting the file.
     *
     * @access public
     * @param  string $name
     * @return int
     */
    public function repair(string $name): int
    {
        $path = $this->resolveMigrationPath($name);
        if ($path === null) {
            return 1;
        }

        try {
            $file = Discovery::load($path);
            $this->tracker->ensureTable();
        } catch (MigrationError $e) {
            $this->say("error: {$e->getMessage()}");

            return 1;
        }

        if ($this->tracker->updateChecksum($file->name, $file->checksum) === false) {
            $this->say(
                "error: {$file->name} has no tracking row - it was never applied, so there is nothing to repair"
            );

            return 1;
        }

        $this->say("repaired {$file->name}: checksum re-stamped to " . substr($file->checksum, 0, 12) . '...');

        return 0;
    }

    /**
     * Drop a migration's tracking row.
     *
     * The operator's way out of MISSING and FAILED. Deliberately does not touch the schema
     * - it only changes what the tool believes, and says so.
     *
     * @access public
     * @param  string $name
     * @return int
     */
    public function forget(string $name): int
    {
        if ($name !== basename($name)) {
            $this->say("error: \"{$name}\" must be a plain migration filename, not a path");

            return 1;
        }

        try {
            $this->tracker->ensureTable();
        } catch (MigrationError $e) {
            $this->say("error: {$e->getMessage()}");

            return 1;
        }

        if ($this->tracker->forget($name) === false) {
            $this->say("error: no tracking row for \"{$name}\"");

            return 1;
        }

        $this->say("forgot {$name}; the database schema was not touched.");

        return 0;
    }

    /**
     * Resolve a bare migration filename to a path inside the migrations directory.
     *
     * A bare basename only: "../elsewhere/x.sql" resolves outside the directory, and
     * stamping that file's checksum under its basename would leave the real migration
     * permanently in DRIFT while reporting a successful repair.
     *
     * @access private
     * @param  string $name
     * @return ?string
     */
    private function resolveMigrationPath(string $name): ?string
    {
        if ($name !== basename($name)) {
            $this->say("error: \"{$name}\" must be a plain migration filename, not a path");

            return null;
        }

        $path = rtrim($this->directory, '/') . '/' . $name;
        if (is_file($path) === false) {
            $this->say("error: no such migration file: {$path}");

            return null;
        }

        return $path;
    }
}
