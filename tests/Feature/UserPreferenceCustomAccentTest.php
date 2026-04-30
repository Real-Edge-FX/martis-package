<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Models\UserPreference;

/*
 * v1.7.4 regression — `accent` column is a plain string, NOT an
 * AccentColor enum cast. The previous cast silently dropped any
 * custom accent name (registered via MARTIS_CUSTOM_ACCENTS) on read
 * and crashed `toPayload()` on `null->value`.
 */

beforeEach(function () {
    if (! Schema::hasTable('martis_user_preferences')) {
        Schema::create('martis_user_preferences', function ($t) {
            $t->id();
            $t->foreignId('user_id');
            $t->string('theme')->default('dark');
            $t->string('accent')->default('martis');
            $t->string('brand_color')->nullable();
            $t->string('density')->default('comfortable');
            $t->string('locale')->default('en');
            $t->boolean('reduced_motion')->default(false);
            $t->timestamps();
        });
    }
});

afterEach(function () {
    UserPreference::query()->delete();
});

it('persists a bundled accent value and round-trips through toPayload', function () {
    UserPreference::create([
        'user_id' => 999,
        'theme' => 'dark',
        'accent' => 'teal',
        'density' => 'comfortable',
        'locale' => 'en',
    ]);

    $row = UserPreference::where('user_id', 999)->first();
    expect($row->accent)->toBe('teal');
    expect($row->toPayload()['accent'])->toBe('teal');
});

it('persists a custom accent name and round-trips through toPayload', function () {
    UserPreference::create([
        'user_id' => 999,
        'theme' => 'dark',
        'accent' => 'edgeflow',
        'density' => 'comfortable',
        'locale' => 'en',
    ]);

    $row = UserPreference::where('user_id', 999)->first();
    expect($row->accent)->toBe('edgeflow');
    expect($row->toPayload()['accent'])->toBe('edgeflow');
});

it('toPayload never crashes when accent is unknown to the enum', function () {
    // Direct DB write bypassing model casts — simulates a row that was
    // saved by an older version with a custom name and is now read by
    // the current code.
    DB::table('martis_user_preferences')->insert([
        'user_id' => 999,
        'theme' => 'dark',
        'accent' => 'arbitrary-custom-name',
        'density' => 'comfortable',
        'locale' => 'en',
        'reduced_motion' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = UserPreference::where('user_id', 999)->first();
    expect($row->toPayload()['accent'])->toBe('arbitrary-custom-name');
});
