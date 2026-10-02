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

    // Each row is named by an opaque handle, never by its `sessions.id`.
    expect($result['supported'])->toBeTrue()
        ->and($result['sessions'])->toHaveCount(2)
        ->and($result['sessions'][0]['id'])->toBe($service->handle($user, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'))
        ->and($result['sessions'][0]['is_current'])->toBeTrue()
        ->and($result['sessions'][1]['id'])->toBe($service->handle($user, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'))
        ->and($result['sessions'][1]['is_current'])->toBeFalse();
});

it('never gives the raw session id of any device to the client', function () {
    $user = makeSessionsUser();
    $raw = ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'];

    DB::table('sessions')->insert([
        ['id' => $raw[0], 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'mac', 'payload' => 'x', 'last_activity' => 100],
        ['id' => $raw[1], 'user_id' => $user->id, 'ip_address' => '10.0.0.5', 'user_agent' => 'iphone', 'payload' => 'y', 'last_activity' => 50],
    ]);

    $service = app(BrowserSessionsService::class);
    $json = json_encode($service->forUser($user, sessionsRequest($raw[0])), JSON_THROW_ON_ERROR);

    foreach ($raw as $id) {
        expect($json)->not->toContain($id);
    }

    // The handle is stable (the UI can key on it), long, and neither the id nor a prefix of it.
    $handles = array_column($service->forUser($user, sessionsRequest($raw[0]))['sessions'], 'id');
    expect($handles)->toBe(array_column($service->forUser($user, sessionsRequest($raw[0]))['sessions'], 'id'))
        ->and($handles[0])->not->toBe($handles[1])
        ->and(strlen($handles[0]))->toBe(64)
        ->and($handles[0])->toMatch('/^[a-f0-9]+$/');
});

it('gives a session a handle of its own for each user and for each app key', function () {
    $user = makeSessionsUser();
    $other = makeSessionsUser();
    $service = app(BrowserSessionsService::class);
    $id = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    $handle = $service->handle($user, $id);

    expect($service->handle($other, $id))->not->toBe($handle);

    config()->set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));
    expect($service->handle($user, $id))->not->toBe($handle);
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

    // Revoking the current session is a deliberate no-op.
    $result = $service->revoke($user, sessionsRequest('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'), $service->handle($user, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
    expect($result['revoked'])->toBe(0)
        ->and(DB::table('sessions')->where('id', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')->exists())->toBeTrue();

    // Revoking another session by its handle removes exactly that row.
    $result = $service->revoke($user, sessionsRequest('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'), $service->handle($user, 'ffffffffffffffffffffffffffffffffffffffff'));
    expect($result['revoked'])->toBe(1)
        ->and(DB::table('sessions')->where('id', 'ffffffffffffffffffffffffffffffffffffffff')->exists())->toBeFalse();
});

it('revokes nothing by the raw session id, or by a handle it does not know', function () {
    $user = makeSessionsUser();

    DB::table('sessions')->insert([
        ['id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => 100, 'ip_address' => null, 'user_agent' => null],
        ['id' => 'ffffffffffffffffffffffffffffffffffffffff', 'user_id' => $user->id, 'payload' => 'y', 'last_activity' => 90, 'ip_address' => null, 'user_agent' => null],
    ]);

    $service = app(BrowserSessionsService::class);
    $current = sessionsRequest('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    foreach (['ffffffffffffffffffffffffffffffffffffffff', str_repeat('0', 64), '', 'x'] as $unknown) {
        expect($service->revoke($user, $current, $unknown)['revoked'])->toBe(0);
    }
    expect(DB::table('sessions')->count())->toBe(2);
});

it('revoke does not delete sessions belonging to other users', function () {
    $user = makeSessionsUser();
    $other = makeSessionsUser();

    DB::table('sessions')->insert([
        ['id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => 100, 'ip_address' => null, 'user_agent' => null],
        ['id' => '9999999999999999999999999999999999999999', 'user_id' => $other->id, 'payload' => 'y', 'last_activity' => 90, 'ip_address' => null, 'user_agent' => null],
    ]);

    $service = app(BrowserSessionsService::class);

    // Neither the other user's handle (computed for them) nor one computed for this user's own scope names their row.
    foreach ([$service->handle($other, '9999999999999999999999999999999999999999'), $service->handle($user, '9999999999999999999999999999999999999999')] as $handle) {
        $result = $service->revoke($user, sessionsRequest('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'), $handle);
        expect($result['revoked'])->toBe(0);
    }

    expect($result['revoked'])->toBe(0)
        ->and(DB::table('sessions')->where('id', '9999999999999999999999999999999999999999')->exists())->toBeTrue();
});

it('lists and revokes sessions over HTTP by their opaque handle only', function () {
    config()->set('martis.guard', null);
    config()->set('auth.guards', ['web' => ['driver' => 'session', 'provider' => 'users']]);
    config()->set('auth.providers.users.model', User::class);
    $user = makeSessionsUser();
    $service = app(BrowserSessionsService::class);

    DB::table('sessions')->insert([
        ['id' => 'dddddddddddddddddddddddddddddddddddddddd', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => 90, 'ip_address' => '10.0.0.2', 'user_agent' => 'tablet'],
        ['id' => 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', 'user_id' => $user->id, 'payload' => 'y', 'last_activity' => 80, 'ip_address' => '10.0.0.3', 'user_agent' => 'phone'],
    ]);

    $this->actingAs($user);
    $list = $this->getJson('/martis/api/profile/sessions')->assertOk()
        ->assertDontSee('dddddddddddddddddddddddddddddddddddddddd')
        ->assertDontSee('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
    $handles = array_column($list->json('sessions'), 'id');
    expect($handles)->toContain($service->handle($user, 'dddddddddddddddddddddddddddddddddddddddd'));

    // The raw id of another device revokes nothing ...
    $this->deleteJson('/martis/api/profile/sessions/dddddddddddddddddddddddddddddddddddddddd')
        ->assertOk()->assertJsonPath('revoked', 0);
    expect(DB::table('sessions')->where('id', 'dddddddddddddddddddddddddddddddddddddddd')->exists())->toBeTrue();

    // ... its handle revokes it.
    $this->deleteJson('/martis/api/profile/sessions/'.$service->handle($user, 'dddddddddddddddddddddddddddddddddddddddd'))
        ->assertOk()->assertJsonPath('revoked', 1);
    expect(DB::table('sessions')->where('id', 'dddddddddddddddddddddddddddddddddddddddd')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee')->exists())->toBeTrue();
});
