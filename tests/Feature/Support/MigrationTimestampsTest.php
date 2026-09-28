<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Martis\Support\MigrationTimestamps;

/*
 * Laravel runs pending migrations sorted by file name. Every name
 * MigrationTimestamps returns is later than the current second, than every
 * migration in the directory and than the name it returned before.
 */

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/martis-migration-timestamps-'.uniqid();
    mkdir($this->dir, 0755, true);
    $this->travelTo(CarbonImmutable::parse('2026-09-27 19:30:35'));
});

afterEach(function () {
    $this->travelBack();

    if (isset($this->dir) && is_dir($this->dir)) {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }
});

it('names the first migration with the current second', function () {
    expect((new MigrationTimestamps($this->dir))->filename('create_things_table'))
        ->toBe('2026_09_27_193035_create_things_table.php');
});

it('gives each later call of the same instance the next second', function () {
    $timestamps = new MigrationTimestamps($this->dir);

    expect($timestamps->filename('create_things_table'))->toBe('2026_09_27_193035_create_things_table.php')
        ->and($timestamps->filename('alter_things_table'))->toBe('2026_09_27_193036_alter_things_table.php')
        ->and($timestamps->filename('drop_things_table'))->toBe('2026_09_27_193037_drop_things_table.php');
});

it('names a migration after one dated in the future', function () {
    touch($this->dir.'/2026_09_27_193036_create_permission_tables.php');

    expect((new MigrationTimestamps($this->dir))->filename('add_category_column_to_permissions_table'))
        ->toBe('2026_09_27_193037_add_category_column_to_permissions_table.php');
});

it('keeps the current second when every migration on disk is older', function () {
    touch($this->dir.'/0001_01_01_000000_create_users_table.php');
    touch($this->dir.'/2026_09_20_101010_create_orders_table.php');

    expect((new MigrationTimestamps($this->dir))->filename('create_things_table'))
        ->toBe('2026_09_27_193035_create_things_table.php');
});

it('ignores files whose name does not start with a timestamp', function () {
    touch($this->dir.'/9999_create_things_table.php');
    touch($this->dir.'/schema.php');

    expect((new MigrationTimestamps($this->dir))->filename('create_things_table'))
        ->toBe('2026_09_27_193035_create_things_table.php');
});

it('reads the directory again on every call', function () {
    $timestamps = new MigrationTimestamps($this->dir);
    $timestamps->filename('create_things_table');

    touch($this->dir.'/2026_09_27_193040_create_permission_tables.php');

    expect($timestamps->filename('add_category_column_to_permissions_table'))
        ->toBe('2026_09_27_193041_add_category_column_to_permissions_table.php');
});

it('treats a missing directory as empty', function () {
    expect((new MigrationTimestamps($this->dir.'/missing'))->filename('create_things_table'))
        ->toBe('2026_09_27_193035_create_things_table.php');
});
