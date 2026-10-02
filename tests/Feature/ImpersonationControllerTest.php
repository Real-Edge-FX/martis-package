<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Contracts\NotImpersonable;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Impersonation\Events\ImpersonationStarted;
use Martis\Impersonation\Events\ImpersonationStopped;
use Martis\Impersonation\Facades\Impersonation;
use Martis\Impersonation\ImpersonationManager;
use Martis\Models\ActionEvent;

class ImpersonationTestUser extends Authenticatable
{
    protected $table = 'impersonation_test_users';

    protected $guarded = [];

    public $timestamps = false;
}

class ProtectedImpersonationTestUser extends Authenticatable implements NotImpersonable
{
    protected $table = 'impersonation_test_users';

    protected $guarded = [];

    public $timestamps = false;
}

/** Nova's per-instance hooks: this operator may not impersonate, this target may not be impersonated. */
class HookedImpersonationTestUser extends Authenticatable
{
    protected $table = 'impersonation_test_users';

    protected $guarded = [];

    public $timestamps = false;

    public function canImpersonate(): bool
    {
        return $this->email !== 'op@example.com';
    }

    public function canBeImpersonated(): bool
    {
        return $this->email !== 'target@example.com';
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('impersonation_test_users');
    Schema::create('impersonation_test_users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password')->nullable();
        $table->unsignedInteger('rank')->default(0);
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

it('start rejects targets that implement NotImpersonable with a 422', function () {
    Gate::define('martis-impersonate', fn () => true);

    // Same physical row as the target, but rebound to a class that
    // implements NotImpersonable so the manager rejects it.
    config()->set('auth.providers.users.model', ProtectedImpersonationTestUser::class);

    $response = $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/'.$this->target->id);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('cannot be impersonated');
});

it('isExpired returns true once max_duration_minutes has elapsed', function () {
    Gate::define('martis-impersonate', fn () => true);
    config()->set('martis.impersonation.max_duration_minutes', 1);

    $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/'.$this->target->id)
        ->assertStatus(200);

    expect(Impersonation::isExpired())->toBeFalse();

    // Backdate the marker so the elapsed window is exceeded.
    $store = app('session.store');
    $stash = $store->get('martis.impersonation');
    $stash['started_at'] = now()->subMinutes(2)->toIso8601String();
    $store->put('martis.impersonation', $stash);

    expect(Impersonation::isExpired())->toBeTrue();
});

it('the duration middleware auto-stops an expired impersonation session', function () {
    Gate::define('martis-impersonate', fn () => true);
    config()->set('martis.impersonation.max_duration_minutes', 1);

    $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/'.$this->target->id)
        ->assertStatus(200);

    $store = app('session.store');
    $stash = $store->get('martis.impersonation');
    $stash['started_at'] = now()->subMinutes(5)->toIso8601String();
    $store->put('martis.impersonation', $stash);

    $response = $this->getJson('/martis/api/impersonation/status');
    $response->assertStatus(200)->assertJson(['active' => false]);

    expect(Impersonation::isActive())->toBeFalse();
});

it('start dispatches ImpersonationStarted and stop dispatches ImpersonationStopped', function () {
    Gate::define('martis-impersonate', fn () => true);
    Event::fake([
        ImpersonationStarted::class,
        ImpersonationStopped::class,
    ]);

    $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/'.$this->target->id)
        ->assertStatus(200);

    Event::assertDispatched(ImpersonationStarted::class);

    $this->postJson('/martis/api/impersonation/stop')->assertStatus(200);

    Event::assertDispatched(ImpersonationStopped::class);
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

it('start answers 422 when the target cannot open the panel (viewMartis)', function () {
    Gate::define('martis-impersonate', fn () => true);
    Gate::define('viewMartis', fn ($user) => $user->email !== 'target@example.com');

    $response = $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/'.$this->target->id);

    $response->assertStatus(422)->assertJson(['message' => 'This user cannot access the panel.']);
    expect(Impersonation::isActive())->toBeFalse();
});

/*
 * Stop runs behind `martis.authorize`, which evaluates the impersonated
 * user. A target the viewMartis gate stops accepting mid-session must still
 * be able to hand the session back (PR #276 review); nothing else opens.
 */
function refuseTheTargetFromNowOn(): void
{
    Gate::define('viewMartis', fn ($user) => $user->email !== 'target@example.com');
}

it('stop hands the session back when the gate refuses the target mid-session', function () {
    Gate::define('martis-impersonate', fn () => true);
    $this->actingAs($this->operator, 'web')->postJson('/martis/api/impersonation/start/'.$this->target->id)->assertOk();

    refuseTheTargetFromNowOn();

    $this->postJson('/martis/api/impersonation/stop')->assertOk()->assertJson(['active' => false]);
    expect(Impersonation::isActive())->toBeFalse()
        ->and(auth()->guard('web')->id())->toBe($this->operator->id);
});

it('keeps every other route closed to a refused target while impersonating', function () {
    Gate::define('martis-impersonate', fn () => true);
    $this->actingAs($this->operator, 'web')->postJson('/martis/api/impersonation/start/'.$this->target->id)->assertOk();

    refuseTheTargetFromNowOn();

    $this->getJson('/martis/api/impersonation/status')->assertForbidden();
    $this->postJson('/martis/api/impersonation/start/'.$this->operator->id)->assertForbidden();
    $this->getJson('/martis/api/command-palette')->assertForbidden();
    expect(Impersonation::isActive())->toBeTrue();
});

it('offers the refused impersonated user a way back in the no-access shell', function () {
    Gate::define('martis-impersonate', fn () => true);
    $this->actingAs($this->operator, 'web')->postJson('/martis/api/impersonation/start/'.$this->target->id)->assertOk();

    refuseTheTargetFromNowOn();

    $this->get('/martis/resources/users')->assertForbidden()
        ->assertSee('panelForbidden: true', false)
        ->assertSee('panelForbiddenImpersonating: true', false);
});

it('does not open stop to a refused user who is not impersonating', function () {
    Gate::define('viewMartis', fn () => false);

    $this->actingAs($this->target, 'web')->postJson('/martis/api/impersonation/stop')->assertForbidden();
    $this->actingAs($this->target, 'web')->get('/martis')->assertForbidden()
        ->assertSee('panelForbiddenImpersonating: false', false);
});

// -----------------------------------------------------------------------------
// The gate sees the target (v2.4.0)
// -----------------------------------------------------------------------------
//
// The gate judged the operator alone, so a support operator it admitted could
// impersonate any user, a super-admin included: NotImpersonable is per class,
// and one users table holds both. The gate now receives the target as its
// second argument, beside the per-instance hooks of Nova.

it('passes the operator and the target to the martis-impersonate gate', function () {
    $seen = null;
    Gate::define('martis-impersonate', function ($operator, $target = null) use (&$seen) {
        $seen = [$operator->id, $target?->id];

        return true;
    });

    $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/'.$this->target->id)
        ->assertOk();

    expect($seen)->toBe([$this->operator->id, $this->target->id]);
});

it('refuses a target the gate says outranks the operator, and starts nothing', function () {
    Gate::define('martis-impersonate', fn ($operator, $target) => $target->rank <= $operator->rank);
    Event::fake([ImpersonationStarted::class]);
    $this->operator->forceFill(['rank' => 1])->save();
    $superAdmin = ImpersonationTestUser::create(['name' => 'Root', 'email' => 'root@example.com', 'rank' => 9]);
    $peer = ImpersonationTestUser::create(['name' => 'Peer', 'email' => 'peer@example.com', 'rank' => 1]);

    $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/'.$superAdmin->id)
        ->assertForbidden()
        ->assertJson(['message' => 'Forbidden.']);

    expect(Impersonation::isActive())->toBeFalse()
        ->and(auth()->guard('web')->id())->toBe($this->operator->id);
    Event::assertNotDispatched(ImpersonationStarted::class);

    // The same operator still impersonates a user the gate lets them.
    $this->postJson('/martis/api/impersonation/start/'.$peer->id)->assertOk();
    expect(auth()->guard('web')->id())->toBe($peer->id);
});

it('keeps a one-argument gate working', function () {
    Gate::define('martis-impersonate', fn ($operator) => $operator->email === 'op@example.com');

    $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/'.$this->target->id)
        ->assertOk();
});

it('answers a missing target like a refusal when the gate refuses the operator, so ids are not an oracle', function () {
    Gate::define('martis-impersonate', fn () => false);

    $this->actingAs($this->operator, 'web');
    $missing = $this->postJson('/martis/api/impersonation/start/999999');
    $existing = $this->postJson('/martis/api/impersonation/start/'.$this->target->id);

    $missing->assertForbidden();
    expect($missing->json())->toBe($existing->json());
});

it('answers 403, not a server error, for a missing target when the gate needs the target', function () {
    Gate::define('martis-impersonate', fn ($operator, $target) => $target->rank <= $operator->rank);

    $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/999999')
        ->assertForbidden();
});

it('answers 403, not a server error, for a missing target whatever way the target-aware gate fails', function (Closure $gate) {
    Gate::define('martis-impersonate', $gate);

    // A missing id is a refusal, not a server error: the 500 against the 403 of an
    // existing refused target would tell every panel user which ids exist.
    $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/999999')
        ->assertForbidden()
        ->assertExactJson(['message' => 'Forbidden.']);
})->with([
    'optional target dereferenced (the documented pattern)' => [fn ($operator, $target = null) => $target->rank <= $operator->rank],
    'optional target, strict type' => [fn ($operator, ?Authenticatable $target = null) => $target->getAuthIdentifier() !== 0],
    'throws' => [function ($operator, $target = null) {
        throw new RuntimeException('gate blew up');
    }],
]);

it('answers an existing target like before with the optional-target gate: allowed, or refused by 403', function () {
    Gate::define('martis-impersonate', fn ($operator, $target = null) => $target->rank <= $operator->rank);
    $this->actingAs($this->operator, 'web');

    $this->postJson('/martis/api/impersonation/start/'.$this->target->id)->assertOk();
    $this->postJson('/martis/api/impersonation/stop')->assertOk();

    $this->target->forceFill(['rank' => 9])->save();
    $this->postJson('/martis/api/impersonation/start/'.$this->target->id)->assertForbidden();
});

it('honours the canImpersonate hook of the operator model with a 403', function () {
    Gate::define('martis-impersonate', fn () => true);
    config()->set('auth.providers.users.model', HookedImpersonationTestUser::class);

    $this->actingAs(HookedImpersonationTestUser::find($this->operator->id), 'web')
        ->postJson('/martis/api/impersonation/start/'.$this->target->id)
        ->assertForbidden();

    expect(Impersonation::isActive())->toBeFalse();
});

it('honours the canBeImpersonated hook of the target model with a 422', function () {
    Gate::define('martis-impersonate', fn () => true);
    config()->set('auth.providers.users.model', HookedImpersonationTestUser::class);
    $other = ImpersonationTestUser::create(['name' => 'Other', 'email' => 'other@example.com']);

    $response = $this->actingAs(HookedImpersonationTestUser::find($other->id), 'web')
        ->postJson('/martis/api/impersonation/start/'.$this->target->id);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('cannot be impersonated')
        ->and(Impersonation::isActive())->toBeFalse();
});

it('refuses a programmatic start that the hooks refuse', function () {
    config()->set('auth.providers.users.model', HookedImpersonationTestUser::class);
    $manager = app(ImpersonationManager::class);

    auth()->guard('web')->setUser(HookedImpersonationTestUser::find($this->operator->id));
    expect(fn () => $manager->start(HookedImpersonationTestUser::find($this->target->id)))
        ->toThrow(RuntimeException::class, 'cannot impersonate');

    auth()->guard('web')->setUser(HookedImpersonationTestUser::create(['name' => 'Free', 'email' => 'free@example.com']));
    expect(fn () => $manager->start(HookedImpersonationTestUser::find($this->target->id)))
        ->toThrow(RuntimeException::class, 'cannot be impersonated');
    expect(Impersonation::isActive())->toBeFalse();
});

it('records both identities in the audit rows of a start and a stop', function () {
    Gate::define('martis-impersonate', fn () => true);
    Schema::dropIfExists('martis_action_events');
    Schema::create('martis_action_events', function ($t) {
        $t->id();
        $t->uuid('batch_id');
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('name');
        $t->string('actionable_type')->nullable();
        $t->string('actionable_id')->nullable();
        $t->string('target_type')->nullable();
        $t->string('target_id')->nullable();
        $t->string('model_type')->nullable();
        $t->string('model_id')->nullable();
        $t->json('fields')->nullable();
        $t->string('status');
        $t->text('exception')->nullable();
        $t->json('original')->nullable();
        $t->json('changes')->nullable();
        $t->timestamps();
    });

    $this->actingAs($this->operator, 'web')->postJson('/martis/api/impersonation/start/'.$this->target->id)->assertOk();
    $this->postJson('/martis/api/impersonation/stop')->assertOk();

    foreach (['impersonation.started', 'impersonation.stopped'] as $name) {
        $row = ActionEvent::query()->where('name', $name)->sole();
        expect($row->user_id)->toBe($this->operator->id)
            ->and($row->fields)->toMatchArray([
                'operator_id' => $this->operator->id,
                'operator_label' => 'Operator',
                'target_id' => $this->target->id,
                'target_label' => 'Target',
            ]);
    }

    Schema::dropIfExists('martis_action_events');
});

it('keeps the message of a non-refusal failure out of the response body (F027)', function () {
    Gate::define('martis-impersonate', fn () => true);
    Event::listen(ImpersonationStarted::class, function () {
        throw new RuntimeException('SQLSTATE[HY000]: General error: no such table secret_internal');
    });

    $response = $this->actingAs($this->operator, 'web')
        ->postJson('/martis/api/impersonation/start/'.$this->target->id);

    $response->assertStatus(500);
    expect($response->getContent())->not->toContain('SQLSTATE')->not->toContain('secret_internal');
});
