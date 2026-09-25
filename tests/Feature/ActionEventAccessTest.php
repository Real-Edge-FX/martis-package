<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Events\GateEvaluated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as EloquentBelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\ActionEventRedactor;
use Martis\Auth\Listeners\RecordAuthorizationDenial;
use Martis\Fields\BelongsTo;
use Martis\Fields\MorphMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Models\ActionEvent;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Resources\ActionEventResource;

// ---------------------------------------------------------------------------
// The audit log is closed by default (gate `view-martis-action-events`,
// or an ActionEvent policy), and `original` / `changes` never show a value
// the viewer could not read on the record's own detail page.
// ---------------------------------------------------------------------------

class AEAUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class AEAAuthor extends Model
{
    protected $table = 'aea_authors';

    protected $guarded = [];

    public $timestamps = false;
}

class AEADoc extends Model
{
    protected $table = 'aea_docs';

    protected $guarded = [];

    protected $hidden = ['token'];

    public $timestamps = false;

    public function author(): EloquentBelongsTo
    {
        return $this->belongsTo(AEAAuthor::class, 'author_id');
    }

    public function actions(): EloquentMorphMany
    {
        return $this->morphMany(ActionEvent::class, 'actionable');
    }
}

/** A model no resource exposes: its events keep every value but `$hidden`. */
class AEAUnexposed extends Model
{
    protected $table = 'aea_docs';

    protected $hidden = ['token'];
}

class AEAAuthorResource extends Resource
{
    public static function model(): string
    {
        return AEAAuthor::class;
    }

    public static function uriKey(): string
    {
        return 'aea-authors';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class AEADocResource extends Resource
{
    public static function model(): string
    {
        return AEADoc::class;
    }

    public static function uriKey(): string
    {
        return 'aea-docs';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            // Only the operator sees the salary.
            Text::make('salary')->canSee(fn (Request $r): bool => $r->user()?->getAttribute('email') === 'operator@example.com'),
            BelongsTo::make('author', 'Author', AEAAuthorResource::class),
            MorphMany::make('Actions', 'actions', ActionEventResource::class),
        ];
    }
}

/** A policy that lets only the operator view documents. */
class AEADocPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AEADoc $doc): bool
    {
        return $user->getAttribute('email') === 'operator@example.com';
    }
}

/** An ActionEvent policy that opens the log to the agent only. */
class AEAActionEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->getAttribute('email') === 'agent@example.com';
    }

    public function view(User $user, ActionEvent $event): bool
    {
        return $this->viewAny($user);
    }
}

function aeaEvent(array $attributes): ActionEvent
{
    return ActionEvent::query()->create(array_merge([
        'batch_id' => 'batch',
        'user_id' => 1,
        'name' => 'Update Doc',
        'actionable_type' => AEADoc::class,
        'actionable_id' => '1',
        'target_type' => AEADoc::class,
        'target_id' => '1',
        'model_type' => AEADoc::class,
        'model_id' => '1',
        'fields' => [],
        'status' => 'completed',
        'exception' => '',
        'original' => [],
        'changes' => [],
    ], $attributes));
}

function aeaOpenLog(): void
{
    Gate::define(ActionEventResource::GATE, fn ($user = null): bool => $user !== null);
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

    Schema::dropIfExists('aea_authors');
    Schema::create('aea_authors', function ($t) {
        $t->id();
        $t->string('name');
    });

    Schema::dropIfExists('aea_docs');
    Schema::create('aea_docs', function ($t) {
        $t->id();
        $t->string('title');
        $t->integer('salary')->nullable();
        $t->string('token')->nullable();
        $t->string('internal_note')->nullable();
        $t->unsignedBigInteger('author_id')->nullable();
    });

    Schema::dropIfExists('martis_action_events');
    Schema::create('martis_action_events', function ($t) {
        $t->id();
        $t->string('batch_id')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('name');
        $t->string('actionable_type')->nullable();
        $t->string('actionable_id')->nullable();
        $t->string('target_type')->nullable();
        $t->string('target_id')->nullable();
        $t->string('model_type')->nullable();
        $t->string('model_id')->nullable();
        $t->text('fields')->nullable();
        $t->string('status')->default('completed');
        $t->text('exception')->nullable();
        $t->text('original')->nullable();
        $t->text('changes')->nullable();
        $t->timestamps();
    });

    $this->operator = AEAUser::query()->create(['name' => 'Operator', 'email' => 'operator@example.com', 'password' => 'x']);
    $this->agent = AEAUser::query()->create(['name' => 'Agent', 'email' => 'agent@example.com', 'password' => 'x']);

    AEAAuthor::query()->create(['id' => 1, 'name' => 'Ann']);
    AEADoc::query()->create(['id' => 1, 'title' => 'New', 'salary' => 200, 'token' => 't2', 'internal_note' => 'n2', 'author_id' => 1]);

    $this->event = aeaEvent([
        'original' => ['title' => 'Old', 'salary' => 100, 'token' => 't1', 'internal_note' => 'n1', 'author_id' => 2],
        'changes' => ['title' => 'New', 'salary' => 200, 'token' => 't2', 'internal_note' => 'n2', 'author_id' => 1],
    ]);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(AEAAuthorResource::class);
    $registry->register(AEADocResource::class);
    $registry->register(ActionEventResource::class);

    Resource::flushPolicyCache();
    ActionEventRedactor::flush();
});

afterEach(function () {
    Schema::dropIfExists('aea_authors');
    Schema::dropIfExists('aea_docs');
    Schema::dropIfExists('martis_action_events');
    app(ResourceRegistry::class)->flush();
    Resource::flushPolicyCache();
    ActionEventRedactor::flush();
});

// --- Access -----------------------------------------------------------------

it('registers the view-martis-action-events gate denying by default', function () {
    expect(Gate::has(ActionEventResource::GATE))->toBeTrue();
    expect(Gate::forUser($this->operator)->allows(ActionEventResource::GATE))->toBeFalse();
});

it('answers 403 on the audit index and detail when the gate is not granted', function () {
    $this->actingAs($this->operator, 'web');

    $this->getJson('/martis/api/resources/action-events')->assertForbidden();
    $this->getJson('/martis/api/resources/action-events/'.$this->event->getKey())->assertForbidden();
});

it('serves the audit index and detail once the host grants the gate', function () {
    aeaOpenLog();
    $this->actingAs($this->operator, 'web');

    $this->getJson('/martis/api/resources/action-events')->assertOk()
        ->assertJsonPath('data.0.name', 'Update Doc');
    $this->getJson('/martis/api/resources/action-events/'.$this->event->getKey())->assertOk();
});

function aeaNavigationUriKeys($test): array
{
    return collect($test->getJson('/martis/api/navigation')->json())
        ->flatMap(fn ($section) => $section['items'] ?? [])
        ->flatMap(fn ($item) => ($item['type'] ?? null) === 'group' ? $item['items'] : [$item])
        ->pluck('uriKey')
        ->filter()
        ->all();
}

it('leaves the sidebar without the audit entry until the gate is granted', function () {
    $this->actingAs($this->operator, 'web');

    expect(aeaNavigationUriKeys($this))->toContain('aea-docs')->not->toContain('action-events');
});

it('lists the audit entry in the sidebar once the gate is granted', function () {
    aeaOpenLog();
    $this->actingAs($this->operator, 'web');

    expect(aeaNavigationUriKeys($this))->toContain('action-events');
});

it('lets an ActionEvent policy decide instead of the gate', function () {
    Gate::policy(ActionEvent::class, AEAActionEventPolicy::class);
    aeaOpenLog();

    $this->actingAs($this->agent, 'web');
    $this->getJson('/martis/api/resources/action-events')->assertOk();

    $this->actingAs($this->operator, 'web');
    $this->getJson('/martis/api/resources/action-events')->assertForbidden();
});

it('lists no action events in a relationship panel without access, and lists them with it', function () {
    $this->actingAs($this->operator, 'web');

    $url = '/martis/api/resources/aea-docs/1/morph-many/actions';

    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');

    aeaOpenLog();

    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
});

it('does not record the audit gate denial built with every navigation by default', function () {
    config()->set('martis.audit.authz_denials', true);

    $this->actingAs($this->operator, 'web');
    $before = ActionEvent::query()->count();

    // The same listener records another denied ability (control) ...
    (new RecordAuthorizationDenial)->handle(new GateEvaluated($this->operator, 'update', false, []));
    expect(ActionEvent::query()->count())->toBe($before + 1);

    // ... and skips the audit gate, as it skips the viewAny cascade.
    (new RecordAuthorizationDenial)->handle(new GateEvaluated($this->operator, ActionEventResource::GATE, false, []));
    expect(ActionEvent::query()->count())->toBe($before + 1);

    config()->set('martis.audit.authz_denials_include_viewany', true);
    (new RecordAuthorizationDenial)->handle(new GateEvaluated($this->operator, ActionEventResource::GATE, false, []));
    expect(ActionEvent::query()->count())->toBe($before + 2);
});

// --- Redaction --------------------------------------------------------------

function aeaDetailValue($response, string $attribute): array
{
    $value = $response->json('data.'.$attribute);

    return is_string($value) ? json_decode($value, true) : (array) $value;
}

it('masks the values of fields the viewer cannot see, and attributes no field shows', function () {
    aeaOpenLog();
    $this->actingAs($this->agent, 'web');

    $response = $this->getJson('/martis/api/resources/action-events/'.$this->event->getKey())->assertOk();

    $original = aeaDetailValue($response, 'original');
    $changes = aeaDetailValue($response, 'changes');

    // Visible fields keep their values, the BelongsTo's foreign key included.
    expect($original['title'])->toBe('Old');
    expect($changes['title'])->toBe('New');
    expect($changes['author_id'])->toBe(1);

    // canSee() hides the salary from the agent; no field shows the others.
    foreach (['salary', 'token', 'internal_note'] as $key) {
        expect($original[$key])->toBe(ActionEventRedactor::MASK);
        expect($changes[$key])->toBe(ActionEventRedactor::MASK);
    }
});

it('shows the values of a field the viewer can see', function () {
    aeaOpenLog();
    $this->actingAs($this->operator, 'web');

    $response = $this->getJson('/martis/api/resources/action-events/'.$this->event->getKey())->assertOk();

    expect(aeaDetailValue($response, 'original')['salary'])->toBe(100);
    expect(aeaDetailValue($response, 'changes')['salary'])->toBe(200);
    expect(aeaDetailValue($response, 'changes')['token'])->toBe(ActionEventRedactor::MASK);
});

it('masks every value of an event on a record the viewer may not view', function () {
    Gate::policy(AEADoc::class, AEADocPolicy::class);
    aeaOpenLog();
    $this->actingAs($this->agent, 'web');

    $response = $this->getJson('/martis/api/resources/action-events/'.$this->event->getKey())->assertOk();

    expect(array_unique(array_values(aeaDetailValue($response, 'changes'))))->toBe([ActionEventRedactor::MASK]);
});

it('keeps the values of an event on a model no resource exposes, but its hidden attributes', function () {
    aeaOpenLog();
    $this->actingAs($this->agent, 'web');

    $event = aeaEvent([
        'actionable_type' => AEAUnexposed::class,
        'model_type' => AEAUnexposed::class,
        'changes' => ['ids' => [1, 2], 'token' => 'secret'],
    ]);

    $response = $this->getJson('/martis/api/resources/action-events/'.$event->getKey())->assertOk();

    expect(aeaDetailValue($response, 'changes'))->toBe(['ids' => [1, 2], 'token' => ActionEventRedactor::MASK]);
});

it('keeps the pivot values of a pivot action event, but the pivot model hidden attributes', function () {
    aeaOpenLog();
    $this->actingAs($this->agent, 'web');

    $event = aeaEvent([
        'model_type' => AEAUnexposed::class,
        'changes' => ['role' => 'editor', 'token' => 'secret'],
    ]);

    $response = $this->getJson('/martis/api/resources/action-events/'.$event->getKey())->assertOk();

    expect(aeaDetailValue($response, 'changes'))->toBe(['role' => 'editor', 'token' => ActionEventRedactor::MASK]);
});

it('still applies the field visibility when the record was deleted', function () {
    aeaOpenLog();
    $this->actingAs($this->agent, 'web');

    AEADoc::query()->whereKey(1)->delete();

    $response = $this->getJson('/martis/api/resources/action-events/'.$this->event->getKey())->assertOk();
    $changes = aeaDetailValue($response, 'changes');

    expect($changes['title'])->toBe('New');
    expect($changes['salary'])->toBe(ActionEventRedactor::MASK);
});
