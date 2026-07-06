<?php

use Martis\SearchResolver;

// ---------------------------------------------------------------------------
// SearchResolver::likeOperator() — the single source of truth for the
// case-insensitive LIKE operator across every filter call-site. PostgreSQL's
// LIKE is case-sensitive (needs ILIKE); MySQL/SQLite LIKE is already
// case-insensitive (keeps LIKE, byte-for-byte unchanged).
//
// The Pest suite runs on SQLite, whose LIKE is case-insensitive, so a
// search-level test would pass even without the fix and prove nothing. We
// test the operator CHOICE directly instead.
// ---------------------------------------------------------------------------

it('returns ilike for PostgreSQL', function () {
    expect(SearchResolver::likeOperator('pgsql'))->toBe('ilike');
});

it('returns like for MySQL (unchanged)', function () {
    expect(SearchResolver::likeOperator('mysql'))->toBe('like');
});

it('returns like for SQLite (unchanged)', function () {
    expect(SearchResolver::likeOperator('sqlite'))->toBe('like');
});

it('returns like for SQL Server (unchanged)', function () {
    expect(SearchResolver::likeOperator('sqlsrv'))->toBe('like');
});

it('returns like when the driver is unknown or null (safe default)', function () {
    expect(SearchResolver::likeOperator(null))->toBe('like');
    expect(SearchResolver::likeOperator('mystery-db'))->toBe('like');
});
