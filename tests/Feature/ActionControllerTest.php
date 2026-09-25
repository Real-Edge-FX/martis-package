<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Actions\DestructiveAction;
use Martis\Actions\Jobs\ExecuteAction;
use Martis\Contracts\ActionContract;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Support\IndexScope;

// ── Test Fixtures ────────────────────────────────────────────────

class ActionTestModel extends Model
{
    protected $table = 'action_test_items';

    protected $fillable = ['title', 'status'];
}

// Policy with runAction / runDestructiveAction defined
class ActionTestPolicyWithRunAction
{
    public function viewAny($user): bool
    {
        return true;
    }

    public function view($user, $model): bool
    {
        return true;
    }

    public function update($user, $model): bool
    {
        return false; // update denied, but runAction allowed
    }

    public function delete($user, $model): bool
    {
        return false; // delete denied, but runDestructiveAction allowed
    }

    public function runAction($user): bool
    {
        return true;
    }

    public function runDestructiveAction($user): bool
    {
        return true;
    }
}

// Policy where runAction/runDestructiveAction NOT defined → falls back to update/delete
class ActionTestPolicyFallback
{
    public function viewAny($user): bool
    {
        return true;
    }

    public function view($user, $model): bool
    {
        return true;
    }

    public function update($user, $model): bool
    {
        return true;
    }

    public function delete($user, $model): bool
    {
        return false; // destructive actions denied via delete fallback
    }
}

// Policy that denies everything
class ActionTestRestrictivePolicy
{
    public function viewAny($user): bool
    {
        return true;
    }

    public function view($user, $model): bool
    {
        return true;
    }

    public function update($user, $model): bool
    {
        return false;
    }

    public function delete($user, $model): bool
    {
        return false;
    }
}

// ── Actions ──────────────────────────────────────────────────────

class ActionTestPublish extends Action
{
    public ?string $name = 'Publish';

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        foreach ($models as $m) {
            $m->update(['status' => 'published']);
        }

        return ActionResponse::message('Published.');
    }
}

class ActionTestArchive extends DestructiveAction
{
    public ?string $name = 'Archive';

    public function handle(ActionFields $fields, Collection $models): ActionResponse|DestructiveAction|null
    {
        return ActionResponse::danger('Archived.');
    }
}

class ActionTestAdminOnly extends Action
{
    public ?string $name = 'Admin Only';

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('Admin action.');
    }
}

class ActionTestWithFields extends Action
{
    public ?string $name = 'With Fields';

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message("Reason: {$fields->reason}");
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('reason', 'Reason')->required(),
        ];
    }
}

class ActionTestStandalone extends Action
{
    public ?string $name = 'Standalone Export';

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('Exported.');
    }
}

class ActionTestSole extends Action
{
    public ?string $name = 'Sole Action';

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('Sole done on '.$models->first()->title);
    }
}

// ── Resources (each with unique uriKey override) ─────────────────

class ActionTestOpenResource extends Resource
{
    public static ?string $policy = ActionTestPolicyWithRunAction::class;

    public static function model(): string
    {
        return ActionTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'act-open-items';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->required(),
            Text::make('status'),
        ];
    }

    /** @return list<ActionContract> */
    public function actions(Request $request): array
    {
        return [
            ActionTestPublish::make()->showInline()->icon('rocket-launch'),
            ActionTestArchive::make()->icon('archive-box'),
            ActionTestAdminOnly::make()
                ->canSee(fn ($r) => $r->user()?->email === 'admin@test.local')
                ->icon('star'),
            ActionTestWithFields::make(),
            ActionTestStandalone::make()->standalone()->onlyOnIndex(),
            ActionTestSole::make()->sole(),
        ];
    }
}

class ActionTestFallbackResource extends Resource
{
    public static ?string $policy = ActionTestPolicyFallback::class;

    public static function model(): string
    {
        return ActionTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'act-fallback-items';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')->required()];
    }

    /** @return list<ActionContract> */
    public function actions(Request $request): array
    {
        return [
            ActionTestPublish::make(),
            ActionTestArchive::make(),
        ];
    }
}

class ActionTestRestrictedResource extends Resource
{
    public static ?string $policy = ActionTestRestrictivePolicy::class;

    public static function model(): string
    {
        return ActionTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'act-restricted-items';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')->required()];
    }

    /** @return list<ActionContract> */
    public function actions(Request $request): array
    {
        return [
            ActionTestPublish::make()
                ->canRun(fn ($r, $m) => $m->status === 'draft'),
            ActionTestArchive::make(),
        ];
    }
}

// ── Setup ────────────────────────────────────────────────────────

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('action_test_items');
    Schema::create('action_test_items', function ($table) {
        $table->id();
        $table->string('title');
        $table->string('status')->default('draft');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(ActionTestOpenResource::class);
    $registry->register(ActionTestFallbackResource::class);
    $registry->register(ActionTestRestrictedResource::class);
    $registry->register(ActionTestTenantScopedResource::class);
    $registry->register(ActionTestOrWhereIndexQueryResource::class);
    $registry->register(ActionTestOrWhereScopesResource::class);

    $this->user = (new Authenticatable)->forceFill([
        'id' => 1,
        'name' => 'Test User',
        'email' => 'admin@test.local',
    ]);
    $this->actingAs($this->user);
});

afterEach(function () {
    Schema::dropIfExists('action_test_items');
    Resource::flushPolicyCache();
});

// ── Action listing ───────────────────────────────────────────────

it('lists available actions for a resource', function () {
    $uri = 'act-open-items';

    $response = $this->getJson("/martis/api/resources/{$uri}/actions");

    $response->assertStatus(200);
    $actions = $response->json('data.actions');
    expect($actions)->toBeArray();
    // 5 visible on index (AdminOnly visible to admin, Standalone visible on index)
    // ActionTestSole has showOnIndex=true by default
    expect(count($actions))->toBeGreaterThanOrEqual(5);
});

it('filters actions by canSee — admin sees admin-only action', function () {
    $uri = 'act-open-items';

    $response = $this->getJson("/martis/api/resources/{$uri}/actions");

    $actions = $response->json('data.actions');
    $names = array_column($actions, 'name');
    expect($names)->toContain('Admin Only');
});

it('filters actions by canSee — non-admin cannot see admin-only action', function () {
    $this->user = (new Authenticatable)->forceFill([
        'id' => 2,
        'name' => 'Normal User',
        'email' => 'user@test.local',
    ]);
    $this->actingAs($this->user);

    $uri = 'act-open-items';

    $response = $this->getJson("/martis/api/resources/{$uri}/actions");

    $actions = $response->json('data.actions');
    $names = array_column($actions, 'name');
    expect($names)->not->toContain('Admin Only');
});

it('serializes action metadata including icon and group', function () {
    $uri = 'act-open-items';

    $response = $this->getJson("/martis/api/resources/{$uri}/actions");

    $actions = $response->json('data.actions');
    $publish = collect($actions)->firstWhere('name', 'Publish');
    expect($publish['icon'])->toBe('rocket-launch');
    expect($publish['showInline'])->toBeTrue();
});

// ── Execute action — normal + policy runAction ───────────────────

it('executes a normal action when runAction policy allows', function () {
    $item = ActionTestModel::create(['title' => 'Test Post', 'status' => 'draft']);
    $uri = 'act-open-items';

    $response = $this->postJson("/martis/api/resources/{$uri}/actions/action-test-publish", [
        'resources' => [$item->id],
    ]);

    $response->assertStatus(200);
    expect($item->fresh()->status)->toBe('published');
});

// ── Execute action — policy fallback to update ──────────────────

it('executes normal action when no runAction but update allows (fallback)', function () {
    $item = ActionTestModel::create(['title' => 'Fallback Test', 'status' => 'draft']);
    $uri = 'act-fallback-items';

    $response = $this->postJson("/martis/api/resources/{$uri}/actions/action-test-publish", [
        'resources' => [$item->id],
    ]);

    $response->assertStatus(200);
});

// ── Destructive action — policy runDestructiveAction ────────────

it('executes destructive action when runDestructiveAction allows', function () {
    $item = ActionTestModel::create(['title' => 'Archive Me', 'status' => 'draft']);
    $uri = 'act-open-items';

    $response = $this->postJson("/martis/api/resources/{$uri}/actions/action-test-archive", [
        'resources' => [$item->id],
    ]);

    $response->assertStatus(200);
});

// ── Destructive action — fallback to delete (denied) ────────────

it('denies destructive action when no runDestructiveAction and delete denies (fallback)', function () {
    $item = ActionTestModel::create(['title' => 'No Delete', 'status' => 'draft']);
    $uri = 'act-fallback-items';

    $response = $this->postJson("/martis/api/resources/{$uri}/actions/action-test-archive", [
        'resources' => [$item->id],
    ]);

    $response->assertStatus(404);
});

// ── canRun per-model enforcement ────────────────────────────────

it('denies action when canRun passes but policy denies (both must pass)', function () {
    // canRun allows draft, but RestrictivePolicy denies update — auth chain requires both
    $item = ActionTestModel::create(['title' => 'Draft Item', 'status' => 'draft']);
    $uri = 'act-restricted-items';

    $response = $this->postJson("/martis/api/resources/{$uri}/actions/action-test-publish", [
        'resources' => [$item->id],
    ]);

    $response->assertStatus(404);
});

it('allows action when both canRun and policy allow (fallback resource)', function () {
    // FallbackResource: no canRun on Publish, policy update=true
    $item = ActionTestModel::create(['title' => 'Allowed Item', 'status' => 'draft']);
    $uri = 'act-fallback-items';

    $response = $this->postJson("/martis/api/resources/{$uri}/actions/action-test-publish", [
        'resources' => [$item->id],
    ]);

    $response->assertStatus(200);
    expect($item->fresh()->status)->toBe('published');
});

it('denies action when canRun callback fails for model', function () {
    $item = ActionTestModel::create(['title' => 'Published Item', 'status' => 'published']);
    $uri = 'act-restricted-items';

    $response = $this->postJson("/martis/api/resources/{$uri}/actions/action-test-publish", [
        'resources' => [$item->id],
    ]);

    $response->assertStatus(404);
});

// ── Bulk action — mixed authorization ───────────────────────────

it('denies bulk action when some models fail canRun', function () {
    $draft = ActionTestModel::create(['title' => 'Draft', 'status' => 'draft']);
    $published = ActionTestModel::create(['title' => 'Published', 'status' => 'published']);
    $uri = 'act-restricted-items';

    $response = $this->postJson("/martis/api/resources/{$uri}/actions/action-test-publish", [
        'resources' => [$draft->id, $published->id],
    ]);

    $response->assertStatus(404);
});

// ── Restrictive policy — denies destructive action via delete fallback

it('denies destructive action when restrictive policy denies delete', function () {
    $item = ActionTestModel::create(['title' => 'Locked', 'status' => 'draft']);
    $uri = 'act-restricted-items';

    $response = $this->postJson("/martis/api/resources/{$uri}/actions/action-test-archive", [
        'resources' => [$item->id],
    ]);

    $response->assertStatus(404);
});

// ── Action fields endpoint ──────────────────────────────────────

it('returns action fields', function () {
    $uri = 'act-open-items';

    $response = $this->getJson("/martis/api/resources/{$uri}/actions/action-test-with-fields/fields");

    $response->assertStatus(200);
    $fields = $response->json('data.fields');
    expect($fields)->toBeArray();
    expect(count($fields))->toBe(1);
    expect($fields[0]['attribute'])->toBe('reason');
});

// ── Action with fields execution ────────────────────────────────

it('executes action with submitted field values', function () {
    $item = ActionTestModel::create(['title' => 'Field Test', 'status' => 'draft']);
    $uri = 'act-open-items';

    $response = $this->postJson("/martis/api/resources/{$uri}/actions/action-test-with-fields", [
        'resources' => [$item->id],
        'fields' => ['reason' => 'Testing fields'],
    ]);

    $response->assertStatus(200);
});

// ── Standalone action (no models required) ──────────────────────

it('executes standalone action without model selection', function () {
    $uri = 'act-open-items';

    $response = $this->postJson("/martis/api/resources/{$uri}/actions/action-test-standalone", [
        'resources' => [],
    ]);

    $response->assertStatus(200);
});

// ── Sole action enforcement ─────────────────────────────────────

it('allows sole action with exactly one model', function () {
    $item = ActionTestModel::create(['title' => 'Sole Item', 'status' => 'draft']);
    $uri = 'act-open-items';

    $response = $this->postJson("/martis/api/resources/{$uri}/actions/action-test-sole", [
        'resources' => [$item->id],
    ]);

    $response->assertStatus(200);
});

it('rejects sole action with multiple models', function () {
    $item1 = ActionTestModel::create(['title' => 'One', 'status' => 'draft']);
    $item2 = ActionTestModel::create(['title' => 'Two', 'status' => 'draft']);
    $uri = 'act-open-items';

    $response = $this->postJson("/martis/api/resources/{$uri}/actions/action-test-sole", [
        'resources' => [$item1->id, $item2->id],
    ]);

    $response->assertStatus(422);
});

// ── Single-resource execution endpoint ──────────────────────────

it('executes action via single-resource endpoint', function () {
    $item = ActionTestModel::create(['title' => 'Single', 'status' => 'draft']);
    $uri = 'act-open-items';

    $response = $this->postJson("/martis/api/resources/{$uri}/{$item->id}/actions/action-test-publish");

    $response->assertStatus(200);
    expect($item->fresh()->status)->toBe('published');
});

// ── 404 for nonexistent action ──────────────────────────────────

it('returns 404 for nonexistent action', function () {
    $item = ActionTestModel::create(['title' => 'Test', 'status' => 'draft']);
    $uri = 'act-open-items';

    $response = $this->postJson("/martis/api/resources/{$uri}/actions/nonexistent-action", [
        'resources' => [$item->id],
    ]);

    $response->assertStatus(404);
});

// ── Context filtering ───────────────────────────────────────────

it('filters actions by context=inline', function () {
    $uri = 'act-open-items';

    $response = $this->getJson("/martis/api/resources/{$uri}/actions?context=inline");

    $response->assertStatus(200);
    $actions = $response->json('data.actions');
    foreach ($actions as $action) {
        expect($action['showInline'])->toBeTrue();
    }
});

// ---------------------------------------------------------------------------
// IDOR guard — resolveModels() must honour the resource indexQuery scope
// ---------------------------------------------------------------------------

class ActionTestScopedResource extends Resource
{
    public static ?string $policy = ActionTestPolicyWithRunAction::class;

    public static function model(): string
    {
        return ActionTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'act-scoped-items';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->required(),
            Text::make('status'),
        ];
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    /** @return list<ActionContract> */
    public function actions(Request $request): array
    {
        return [ActionTestPublish::make()];
    }
}

it('an action cannot reach records outside the resource indexQuery scope (IDOR guard)', function () {
    app(ResourceRegistry::class)->register(ActionTestScopedResource::class);

    $inScope = ActionTestModel::create(['title' => 'Draft', 'status' => 'draft']);
    $outOfScope = ActionTestModel::create(['title' => 'Hidden', 'status' => 'archived']);

    // Pass BOTH ids — the archived one is outside the scoped indexQuery.
    $response = $this->postJson('/martis/api/resources/act-scoped-items/actions/action-test-publish', [
        'resources' => [$inScope->id, $outOfScope->id],
    ]);

    $response->assertSuccessful();

    // In-scope record was acted on; the out-of-scope record was never
    // resolved, so the action could not touch it.
    expect($inScope->fresh()->status)->toBe('published')
        ->and($outOfScope->fresh()->status)->toBe('archived');
});

// ---------------------------------------------------------------------------
// The records a run resolves: the resource's scopes() apply as on the index,
// a run whose ids all fail to resolve answers 404, a run that names no record
// answers 422, and a standalone action runs on no record.
// ---------------------------------------------------------------------------

/** Reports how many records handle() received. */
class ActionTestCountRecords extends Action
{
    public ?string $name = 'Count Records';

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('records: '.$models->count());
    }
}

/** A queued action: its job carries the ids of the records it runs on. */
class ActionTestQueuedPublish extends ActionTestPublish implements ShouldQueue
{
    public ?string $name = 'Queued Publish';
}

class ActionTestTenantScopedResource extends Resource
{
    public static ?string $policy = ActionTestPolicyWithRunAction::class;

    public static function model(): string
    {
        return ActionTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'act-tenant-items';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')->required(), Text::make('status')];
    }

    // The tenant scope, as the index applies it.
    public static function scopes(Request $request): array
    {
        return ['tenant' => fn (Builder $query) => $query->where('title', '!=', 'Other tenant')];
    }

    /** @return list<ActionContract> */
    public function actions(Request $request): array
    {
        return [
            ActionTestPublish::make(),
            ActionTestQueuedPublish::make(),
            ActionTestCountRecords::make()->standalone(),
        ];
    }
}

it('does not run an action on a record the resource scopes() keep out, as the index does not list it', function () {
    $mine = ActionTestModel::create(['title' => 'Mine', 'status' => 'draft']);
    $theirs = ActionTestModel::create(['title' => 'Other tenant', 'status' => 'draft']);

    $this->postJson('/martis/api/resources/act-tenant-items/actions/action-test-publish', [
        'resources' => [$mine->id, $theirs->id],
    ])->assertOk();

    expect($mine->fresh()->status)->toBe('published')
        ->and($theirs->fresh()->status)->toBe('draft');
});

it('answers 404 from the single-record endpoint for a record the resource scopes() keep out', function () {
    $theirs = ActionTestModel::create(['title' => 'Other tenant', 'status' => 'draft']);

    $this->postJson("/martis/api/resources/act-tenant-items/{$theirs->id}/actions/action-test-publish")
        ->assertStatus(404)
        ->assertJsonPath('message', 'One or more selected resources could not be found.');

    expect($theirs->fresh()->status)->toBe('draft');
});

it('answers 404 and runs nothing when none of the selected records resolves', function (Closure $ids) {
    $theirs = ActionTestModel::create(['title' => 'Other tenant', 'status' => 'draft']);

    $this->postJson('/martis/api/resources/act-tenant-items/actions/action-test-publish', [
        'resources' => $ids($theirs),
    ])->assertStatus(404)->assertJsonPath('message', 'One or more selected resources could not be found.');

    expect($theirs->fresh()->status)->toBe('draft');
})->with([
    'an id that does not exist' => [fn () => [999]],
    'an id outside the scopes()' => [fn (ActionTestModel $theirs) => [$theirs->id]],
]);

it('refuses a run that names no record for an action that is not standalone', function (array $body) {
    $this->postJson('/martis/api/resources/act-tenant-items/actions/action-test-publish', $body)
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'resources');
})->with([
    'an empty selection' => [['resources' => []]],
    'no selection' => [[]],
]);

it('runs a standalone action on no record, whatever ids the request sends', function () {
    $mine = ActionTestModel::create(['title' => 'Mine', 'status' => 'draft']);

    $this->postJson('/martis/api/resources/act-tenant-items/actions/action-test-count-records', [
        'resources' => [$mine->id, 999],
    ])->assertOk()->assertJsonPath('data.data.message', 'records: 0');
});

it('queues an action with only the records the resource scopes() let through', function () {
    Queue::fake();
    $mine = ActionTestModel::create(['title' => 'Mine', 'status' => 'draft']);
    $theirs = ActionTestModel::create(['title' => 'Other tenant', 'status' => 'draft']);

    $this->postJson('/martis/api/resources/act-tenant-items/actions/action-test-queued-publish', [
        'resources' => [$mine->id, $theirs->id],
    ])->assertOk();

    Queue::assertPushed(
        ExecuteAction::class,
        fn (ExecuteAction $job): bool => $job->modelIds === [$mine->id],
    );
});

// ---------------------------------------------------------------------------
// The hooks run as Eloquent runs a local scope: an orWhere() at their top
// level is grouped before the selected ids are added. Ungrouped,
// `status = 'draft' or title = 'Shared'` followed by `and id in (...)` reads
// `status = 'draft' or (title = 'Shared' and id in (...))`, and an action on
// one selected record ran on every draft.
// ---------------------------------------------------------------------------

class ActionTestOrWhereIndexQueryResource extends Resource
{
    public static ?string $policy = ActionTestPolicyWithRunAction::class;

    public static function model(): string
    {
        return ActionTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'act-or-index-query-items';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')->required(), Text::make('status')];
    }

    // Written as such a hook often is: a top-level orWhere().
    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('status', 'draft')->orWhere('title', 'Shared');
    }

    /** @return list<ActionContract> */
    public function actions(Request $request): array
    {
        return [ActionTestPublish::make()];
    }
}

class ActionTestOrWhereScopesResource extends ActionTestOrWhereIndexQueryResource
{
    public static function uriKey(): string
    {
        return 'act-or-scopes-items';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query;
    }

    public static function scopes(Request $request): array
    {
        return ['visible' => fn (Builder $query) => $query->where('status', 'draft')->orWhere('title', 'Shared')];
    }
}

it('runs an action on the selected record only when a hook has a top-level orWhere()', function (string $uriKey) {
    $other = ActionTestModel::create(['title' => 'Not selected', 'status' => 'draft']);
    $selected = ActionTestModel::create(['title' => 'Selected', 'status' => 'draft']);
    $shared = ActionTestModel::create(['title' => 'Shared', 'status' => 'archived']);

    $this->postJson("/martis/api/resources/{$uriKey}/actions/action-test-publish", [
        'resources' => [$selected->id],
    ])->assertOk();

    expect($selected->fresh()->status)->toBe('published')
        ->and($other->fresh()->status)->toBe('draft')
        ->and($shared->fresh()->status)->toBe('archived');
})->with([
    'indexQuery()' => ['act-or-index-query-items'],
    'scopes()' => ['act-or-scopes-items'],
]);

it('leaves the SQL of hooks with and-clauses only as it was', function () {
    $request = Request::create('/');
    $raw = ActionTestTenantScopedResource::indexQuery($request, ActionTestTenantScopedResource::applyScopes($request, (new ActionTestModel)->newQuery()));
    $grouped = IndexScope::apply($request, ActionTestTenantScopedResource::class, (new ActionTestModel)->newQuery());

    expect($grouped->toSql())->toBe($raw->toSql())
        ->and($grouped->getBindings())->toBe($raw->getBindings());
});
