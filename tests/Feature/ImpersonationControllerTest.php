<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Impersonation\Facades\Impersonation;

class ImpersonationTestUser extends Authenticatable
{
    protected $table = 'impersonation_test_users';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('impersonation_test_users');
    Schema::create('impersonation_test_users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password')->nullable();
    });

    config()->set('auth.providers.users.model', ImpersonationTestUser::class);
    config()->set('martis.impersonation.enabled', true);
    config()->set('martis.impersonation.guard', 'web');
    config()->set('martis.impersonation.session_key', 'martis.impersonation');

    // Default — gate denies impersonation. Individual tests opt in.
    Gate::define('martis-impersonate', fn () => false);

    $this->operator = ImpersonationTestUser::create([
        'name' => 'Operator',
        'email' => 'op@example.com',
    ]);
    $this->target = ImpersonationTestUser::create([
        'name' => 'Target',
        'email' => 'target@example.com',
    ]);
});

afterEach(function () {
    Schema::dropIfExists('impersonation_test_users');
});

it('GET /martis/api/impersonation/status returns the inactive snapshot when no session is running', function () {
    $response = $this->actingAs($this->operator, 'web')
        ->getJson('/martis/api/impersonation/status');

    $response->assertStatus(200)->assertJson([
        'active' => false,
        'enabled' => true,
    ]);
});

it('start returns 503 when the master switch is off', function () {
    config()->set('martis.impersonation.enabled', false);

    $response = $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/'.$this->target->id);

    $response->assertStatus(503)->assertJson(['message' => 'Impersonation is disabled.']);
});

it('start returns 403 when the martis-impersonate gate denies the request', function () {
    Gate::define('martis-impersonate', fn () => false);

    $response = $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/'.$this->target->id);

    $response->assertStatus(403);
});

it('start returns 404 when the target user does not exist', function () {
    Gate::define('martis-impersonate', fn () => true);

    $response = $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/999999');

    $response->assertStatus(404);
});

it('start switches the auth guard to the target and stashes the operator', function () {
    Gate::define('martis-impersonate', fn () => true);

    $response = $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/'.$this->target->id);

    $response->assertStatus(200)->assertJson([
        'active' => true,
        'enabled' => true,
        'original' => ['id' => $this->operator->id, 'label' => 'Operator'],
        'target' => ['id' => $this->target->id, 'label' => 'Target'],
    ]);

    expect(Impersonation::isActive())->toBeTrue();
    expect(auth()->guard('web')->id())->toBe($this->target->id);
});

it('start returns 422 when the operator tries to impersonate themselves', function () {
    Gate::define('martis-impersonate', fn () => true);

    $response = $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/'.$this->operator->id);

    $response->assertStatus(422);
    expect(Impersonation::isActive())->toBeFalse();
});

it('start returns 422 when impersonation is already active (no chaining)', function () {
    Gate::define('martis-impersonate', fn () => true);

    $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/'.$this->target->id)
        ->assertStatus(200);

    $other = ImpersonationTestUser::create(['name' => 'Other', 'email' => 'other@example.com']);

    $response = $this->postJson('/martis/api/impersonation/start/'.$other->id);

    $response->assertStatus(422);
});

it('stop restores the operator and clears the session marker', function () {
    Gate::define('martis-impersonate', fn () => true);

    $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/'.$this->target->id)
        ->assertStatus(200);

    expect(auth()->guard('web')->id())->toBe($this->target->id);

    $response = $this->postJson('/martis/api/impersonation/stop');

    $response->assertStatus(200)->assertJson(['active' => false]);
    expect(Impersonation::isActive())->toBeFalse();
    expect(auth()->guard('web')->id())->toBe($this->operator->id);
});

it('stop is idempotent when no impersonation is active', function () {
    $response = $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/stop');

    $response->assertStatus(200)->assertJson(['active' => false]);
});

it('status reflects the original + target users while impersonation runs', function () {
    Gate::define('martis-impersonate', fn () => true);

    $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/'.$this->target->id)
        ->assertStatus(200);

    $response = $this->getJson('/martis/api/impersonation/status');

    $response->assertStatus(200);
    $payload = $response->json();

    expect($payload['active'])->toBeTrue();
    expect($payload['original']['id'])->toBe($this->operator->id);
    expect($payload['target']['id'])->toBe($this->target->id);
    expect($payload['started_at'])->toBeString();
});
