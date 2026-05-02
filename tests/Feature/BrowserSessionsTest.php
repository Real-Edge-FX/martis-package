<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Profile\BrowserSessionsService;

// -----------------------------------------------------------------------------
// BrowserSessionsService — list + revoke browser sessions
// -----------------------------------------------------------------------------

function makeSessionsUser(): User
{
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /** @var User $user */
    $user = User::forceCreate([
        'name' => 'Test '.rand(1000, 9999),
        'email' => 'test'.rand(1000, 9999).'@example.com',
        'password' => bcrypt('password'),
    ]);

    return $user;
}

beforeEach(function () {
    Schema::dropIfExists('sessions');
    Schema::create('sessions', function ($t) {
        $t->string('id')->primary();
        $t->foreignId('user_id')->nullable()->index();
        $t->string('ip_address', 45)->nullable();
        $t->text('user_agent')->nullable();
        $t->longText('payload');
        $t->integer('last_activity')->index();
    });

    config()->set('session.driver', 'database');
    config()->set('session.table', 'sessions');
});

afterEach(function () {
    Schema::dropIfExists('sessions');
});

function sessionsRequest(string $sessionId)
{
    $request = request();
    $store = app('session.store');
    $store->setId($sessionId);
    $request->setLaravelSession($store);

    return $request;
}

it('returns supported=false when the session driver is not database-backed', function () {
    config()->set('session.driver', 'file');

    $service = app(BrowserSessionsService::class);
    $user = makeSessionsUser();

    $result = $service->forUser($user, sessionsRequest('session-current'));

    expect($result['supported'])->toBeFalse()
        ->and($result['driver'])->toBe('file')
        ->and($result['sessions'])->toBe([]);
});

it('lists every session for the user with is_current true on the active row', function () {
    $user = makeSessionsUser();
    $other = makeSessionsUser();

    DB::table('sessions')->insert([
        ['id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'mac', 'payload' => 'x', 'last_activity' => 100],
        ['id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'user_id' => $user->id, 'ip_address' => '10.0.0.5', 'user_agent' => 'iphone', 'payload' => 'y', 'last_activity' => 50],
        ['id' => 'cccccccccccccccccccccccccccccccccccccccc', 'user_id' => $other->id, 'ip_address' => '10.0.0.7', 'user_agent' => 'tablet', 'payload' => 'z', 'last_activity' => 75],
    ]);

    $service = app(BrowserSessionsService::class);
    $result = $service->forUser($user, sessionsRequest('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));

    expect($result['supported'])->toBeTrue()
        ->and($result['sessions'])->toHaveCount(2)
        ->and($result['sessions'][0]['id'])->toBe('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->and($result['sessions'][0]['is_current'])->toBeTrue()
        ->and($result['sessions'][1]['id'])->toBe('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')
        ->and($result['sessions'][1]['is_current'])->toBeFalse();
});

it('revokeOthers leaves the current session and deletes the rest', function () {
    $user = makeSessionsUser();

    DB::table('sessions')->insert([
        ['id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => 100, 'ip_address' => null, 'user_agent' => null],
        ['id' => 'dddddddddddddddddddddddddddddddddddddddd', 'user_id' => $user->id, 'payload' => 'y', 'last_activity' => 90, 'ip_address' => null, 'user_agent' => null],
        ['id' => 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', 'user_id' => $user->id, 'payload' => 'z', 'last_activity' => 80, 'ip_address' => null, 'user_agent' => null],
    ]);

    $service = app(BrowserSessionsService::class);
    $result = $service->revokeOthers($user, sessionsRequest('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));

    expect($result['revoked'])->toBe(2)
        ->and($result['supported'])->toBeTrue();

    $remaining = DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all();
    expect($remaining)->toBe(['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);
});

it('revoke(id) deletes that single session but never the current one', function () {
    $user = makeSessionsUser();

    DB::table('sessions')->insert([
        ['id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => 100, 'ip_address' => null, 'user_agent' => null],
        ['id' => 'ffffffffffffffffffffffffffffffffffffffff', 'user_id' => $user->id, 'payload' => 'y', 'last_activity' => 90, 'ip_address' => null, 'user_agent' => null],
    ]);

    $service = app(BrowserSessionsService::class);

    // Revoking the current id is a deliberate no-op.
    $result = $service->revoke($user, sessionsRequest('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'), 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    expect($result['revoked'])->toBe(0)
        ->and(DB::table('sessions')->where('id', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')->exists())->toBeTrue();

    // Revoking a different id removes exactly that row.
    $result = $service->revoke($user, sessionsRequest('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'), 'ffffffffffffffffffffffffffffffffffffffff');
    expect($result['revoked'])->toBe(1)
        ->and(DB::table('sessions')->where('id', 'ffffffffffffffffffffffffffffffffffffffff')->exists())->toBeFalse();
});

it('revoke does not delete sessions belonging to other users', function () {
    $user = makeSessionsUser();
    $other = makeSessionsUser();

    DB::table('sessions')->insert([
        ['id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => 100, 'ip_address' => null, 'user_agent' => null],
        ['id' => '9999999999999999999999999999999999999999', 'user_id' => $other->id, 'payload' => 'y', 'last_activity' => 90, 'ip_address' => null, 'user_agent' => null],
    ]);

    $service = app(BrowserSessionsService::class);
    $result = $service->revoke($user, sessionsRequest('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'), '9999999999999999999999999999999999999999');

    expect($result['revoked'])->toBe(0)
        ->and(DB::table('sessions')->where('id', '9999999999999999999999999999999999999999')->exists())->toBeTrue();
});
