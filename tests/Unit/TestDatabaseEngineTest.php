<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Yikes\Tests\TestCase;

uses(TestCase::class);

// This package stores nothing in a database — notes are flat files on disk, and
// no test here reads or writes a table. That is exactly why these assertions
// exist: an unexercised connection can silently rot back to the Testbench
// SQLite default and nothing else in the suite would notice.
//
// Doctrine: every connection is Postgres at the pinned major, in dev, test and
// production alike. See .claude/imports/data-persistence.md
describe('test database engine', function (): void {
    it('binds the test connection to postgres, not an inherited sqlite default', function (): void {
        expect(config('database.connections.testing.driver'))->toBe('pgsql');
    });

    it('actually connects to a live postgres server at the pinned major', function (): void {
        $version = DB::connection('testing')->getPdo()
            ->query('SHOW server_version')->fetchColumn();

        expect($version)->toBeString();
        expect((int) $version)->toBe(18);
    });

    it('can generate a database-side uuid v7, the mandated primary key', function (): void {
        $uuid = DB::connection('testing')->selectOne('SELECT uuidv7() AS id')->id;

        // Version nibble of a v7 uuid sits at the start of the third group.
        expect($uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/');
    });
});
