<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Models\ActionEvent;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Resources\ActionEventResource;

// ---------------------------------------------------------------------------
// The ⌘K "Recent" block reads martis_action_events rows. Every other read
// surface for the audit log (index, navigation, badges, search, detail) goes
// through the ActionEvent resource authorization; the palette must too, or a
// host that restricts the audit log through a policy still leaks the caller's
// own rows through ⌘K.
// ---------------------------------------------------------------------------

class CPAUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

// viewAny is granted to one email only — the "platform operator" shape.
class CPAActionEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->getAttribute('email') === 'operator@example.com';
    }

    public function view(User $user, ActionEvent $event): bool
    {
        return $this->viewAny($user);
    }
}

class CPAUnrelatedModel extends Model
{
    protected $table = 'cpa_unrelated';

    protected $guarded = [];

    public $timestamps = false;
}

class CPAUnrelatedResource extends Resource
{
    public static function model(): string
    {
        return CPAUnrelatedModel::class;
    }

    public static function uriKey(): string
    {
        return 'cpa-unrelated';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->timestamps();
        });
    }

    Schema::dropIfExists('cpa_unrelated');
    Schema::create('cpa_unrelated', function ($t) {
        $t->id();
        $t->string('title');
    });

    Schema::dropIfExists('martis_action_events');
    Schema::create('martis_action_events', function ($t) {
        $t->id();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('name');
        $t->string('model_type')->nullable();
        $t->string('model_id')->nullable();
        $t->string('target_type')->nullable();
        $t->string('status')->default('completed');
        $t->timestamps();
    });

    $this->operator = CPAUser::query()->create([
        'name' => 'Operator',
        'email' => 'operator@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->agent = CPAUser::query()->create([
        'name' => 'Agent',
        'email' => 'agent@example.com',
        'password' => bcrypt('secret'),
    ]);

    foreach ([$this->operator, $this->agent] as $user) {
        DB::table('martis_action_events')->insert([
            'user_id' => $user->getKey(),
            'name' => 'item.updated',
            'model_type' => CPAUnrelatedModel::class,
            'model_id' => '1',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(CPAUnrelatedResource::class);
    $registry->register(ActionEventResource::class);

    Resource::flushPolicyCache();
});

afterEach(function () {
    Schema::dropIfExists('cpa_unrelated');
    Schema::dropIfExists('martis_action_events');
    app(ResourceRegistry::class)->flush();
    Resource::flushPolicyCache();
});

it('hides the recent rows with no ActionEvent policy and no gate (closed by default)', function () {
    $this->actingAs($this->agent, 'web');

    $response = $this->getJson('/martis/api/command-palette')->assertOk();

    expect($response->json('recent'))->toBe([]);
});

it('serves the recent rows with no ActionEvent policy when the gate allows the user', function () {
    Gate::define(ActionEventResource::GATE, fn ($user): bool => $user->getAttribute('email') === 'agent@example.com');

    $this->actingAs($this->agent, 'web');

    $response = $this->getJson('/martis/api/command-palette')->assertOk();

    expect($response->json('recent'))->toHaveCount(1);
    expect($response->json('recent.0.label'))->toBe('item.updated');
});

it('hides the recent rows for a user the ActionEvent policy denies viewAny to', function () {
    Gate::policy(ActionEvent::class, CPAActionEventPolicy::class);

    $this->actingAs($this->agent, 'web');

    $response = $this->getJson('/martis/api/command-palette')->assertOk();

    expect($response->json('recent'))->toBe([]);
    // The rest of the payload is untouched.
    expect(collect($response->json('resources'))->pluck('uriKey')->all())->toContain('cpa-unrelated');
});

it('serves the recent rows to a user the ActionEvent policy allows (same policy)', function () {
    Gate::policy(ActionEvent::class, CPAActionEventPolicy::class);

    $this->actingAs($this->operator, 'web');

    $response = $this->getJson('/martis/api/command-palette')->assertOk();

    expect($response->json('recent'))->toHaveCount(1);
});

it('hides the recent rows for everyone when no registered resource exposes the ActionEvent model', function () {
    // What `martis.action_events.resource = false` produces at boot: the
    // built-in resource is never registered.
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(CPAUnrelatedResource::class);

    $this->actingAs($this->operator, 'web');

    $response = $this->getJson('/martis/api/command-palette')->assertOk();

    expect($response->json('recent'))->toBe([]);
});

it('gates the recent rows through a consumer-registered resource for the ActionEvent model', function () {
    // A host that hides the built-in resource but registers its own
    // (subclass with static::$policy) is gated by that resource's policy.
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(CPAUnrelatedResource::class);
    $registry->register(CPAAuditResource::class);

    $this->actingAs($this->agent, 'web');
    expect($this->getJson('/martis/api/command-palette')->assertOk()->json('recent'))->toBe([]);

    $this->actingAs($this->operator, 'web');
    expect($this->getJson('/martis/api/command-palette')->assertOk()->json('recent'))->toHaveCount(1);
});

class CPAAuditResource extends ActionEventResource
{
    public static ?string $policy = CPAActionEventPolicy::class;

    public static function uriKey(): string
    {
        return 'cpa-audit';
    }
}
