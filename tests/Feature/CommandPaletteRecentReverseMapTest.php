<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Resources\ActionEventResource;

// ---------------------------------------------------------------------------
// Fixtures — two resources share ONE model (CPRItem), scoped by status. This is
// the Approval-Queue vs Processed pattern: a bare first-match reverse-map would
// always pick the first-registered resource, so a processed record's ⌘K Recent
// deep-link would open the wrong surface.
// ---------------------------------------------------------------------------

class CPRUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class CPRItem extends Model
{
    protected $table = 'cpr_items';

    protected $guarded = [];

    public $timestamps = false;
}

// Registered FIRST — so first-match (the old behaviour) would always win.
class CPRPendingResource extends Resource
{
    public static function model(): string
    {
        return CPRItem::class;
    }

    public static function uriKey(): string
    {
        return 'cpr-pending';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }

    public function matchesRecord(Model $model): bool
    {
        return in_array($model->getAttribute('status'), ['pending', 'failed'], true);
    }
}

// Shares CPRItem via inheritance; claims the processed statuses.
class CPRProcessedResource extends CPRPendingResource
{
    public static function uriKey(): string
    {
        return 'cpr-processed';
    }

    public function matchesRecord(Model $model): bool
    {
        return in_array($model->getAttribute('status'), ['approved', 'indexed', 'rejected', 'deleted'], true);
    }
}

// A SOFT-DELETES model shared by two resources — the reverse-map must still
// resolve a trashed record (loaded via withTrashed) to the right surface.
class CPRSoftItem extends Model
{
    use SoftDeletes;

    protected $table = 'cpr_soft_items';

    protected $guarded = [];

    public $timestamps = false;
}

// Registered FIRST — claims live rows.
class CPRSoftActiveResource extends Resource
{
    public static function model(): string
    {
        return CPRSoftItem::class;
    }

    public static function uriKey(): string
    {
        return 'cpr-soft-active';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }

    public function matchesRecord(Model $model): bool
    {
        return ! method_exists($model, 'trashed') || ! $model->trashed();
    }
}

// Claims trashed rows (an "Archived" surface that shows withTrashed()).
class CPRSoftArchivedResource extends CPRSoftActiveResource
{
    public static function uriKey(): string
    {
        return 'cpr-soft-archived';
    }

    public function matchesRecord(Model $model): bool
    {
        return method_exists($model, 'trashed') && $model->trashed();
    }
}

// A model with a page of its own and a headless resource over it, the headless
// one registered FIRST (the agency-member case): the Recent link must name the
// routable resource, whose page exists.
class CPRHeadItem extends Model
{
    protected $table = 'cpr_head_items';

    protected $guarded = [];

    public $timestamps = false;
}

class CPRHeadlessResource extends Resource
{
    public static function model(): string
    {
        return CPRHeadItem::class;
    }

    public static function uriKey(): string
    {
        return 'cpr-headless';
    }

    public static function routable(): bool
    {
        return false;
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }
}

class CPRHeadPageResource extends CPRHeadlessResource
{
    public static function uriKey(): string
    {
        return 'cpr-head-page';
    }

    public static function routable(): bool
    {
        return true;
    }
}

// A headless resource over the audit-log model that refuses the viewer, ahead
// of the routable ActionEventResource that allows them.
class CPRHeadlessAuditResource extends ActionEventResource
{
    public static function uriKey(): string
    {
        return 'cpr-headless-audit';
    }

    public static function routable(): bool
    {
        return false;
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}

// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    // The audit log is closed by default: these tests read it.
    Gate::define(ActionEventResource::GATE, fn ($user = null): bool => true);

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->timestamps();
        });
    }

    Schema::dropIfExists('cpr_items');
    Schema::create('cpr_items', function ($t) {
        $t->id();
        $t->string('title');
        $t->string('status');
    });

    Schema::dropIfExists('cpr_soft_items');
    Schema::create('cpr_soft_items', function ($t) {
        $t->id();
        $t->string('title');
        $t->softDeletes();
    });

    Schema::dropIfExists('cpr_head_items');
    Schema::create('cpr_head_items', function ($t) {
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

    $this->user = CPRUser::query()->create([
        'name' => 'Test',
        'email' => 'cpr@example.com',
        'password' => bcrypt('secret'),
    ]);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    // Register the Approval-Queue resource FIRST so a first-match reverse-map
    // would pick it regardless of the record's status.
    $registry->register(CPRPendingResource::class);
    $registry->register(CPRProcessedResource::class);
    $registry->register(CPRSoftActiveResource::class);
    $registry->register(CPRSoftArchivedResource::class);
    // The flush above also dropped the built-in audit-log resource the
    // service provider registers at boot; the Recent block is gated on it.
    $registry->register(ActionEventResource::class);

    $this->actingAs($this->user, 'web');
});

afterEach(function () {
    Schema::dropIfExists('cpr_items');
    Schema::dropIfExists('cpr_soft_items');
    Schema::dropIfExists('cpr_head_items');
    Schema::dropIfExists('martis_action_events');
    app(ResourceRegistry::class)->flush();
});

function cprLogEvent(int $userId, string $modelType, int $modelId, string $status): void
{
    DB::table('martis_action_events')->insert([
        'user_id' => $userId,
        'name' => 'candidate.'.$status,
        'model_type' => $modelType,
        'model_id' => (string) $modelId,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('reverse-maps a processed record to the Processed surface, not the first-registered resource', function () {
    $record = CPRItem::query()->create(['title' => 'Doc', 'status' => 'indexed']);
    cprLogEvent((int) $this->user->id, CPRItem::class, (int) $record->id, 'indexed');

    $response = $this->getJson('/martis/api/command-palette');

    $response->assertOk();
    $recent = collect($response->json('recent'));
    $entry = $recent->firstWhere('key', 1);

    expect($entry)->not->toBeNull();
    // Without matchesRecord this would be '/resources/cpr-pending/...' (first match).
    expect($entry['url'])->toBe('/resources/cpr-processed/'.$record->id);
});

it('reverse-maps a pending record to the Approval-Queue surface (both directions correct)', function () {
    $record = CPRItem::query()->create(['title' => 'Draft', 'status' => 'pending']);
    cprLogEvent((int) $this->user->id, CPRItem::class, (int) $record->id, 'pending');

    $response = $this->getJson('/martis/api/command-palette');

    $response->assertOk();
    $entry = collect($response->json('recent'))->firstWhere('key', 1);

    expect($entry)->not->toBeNull();
    expect($entry['url'])->toBe('/resources/cpr-pending/'.$record->id);
});

it('reverse-maps a SOFT-DELETED record via withTrashed, not a first-match fallback', function () {
    $record = CPRSoftItem::query()->create(['title' => 'Archived doc']);
    $record->delete(); // soft delete — default scope now hides it
    cprLogEvent((int) $this->user->id, CPRSoftItem::class, (int) $record->id, 'deleted');

    $response = $this->getJson('/martis/api/command-palette');

    $response->assertOk();
    $entry = collect($response->json('recent'))->firstWhere('key', 1);

    expect($entry)->not->toBeNull();
    // Without withTrashed(), whereKey()->first() returns null and the code
    // falls back to the first-registered (cpr-soft-active) — the wrong surface.
    expect($entry['url'])->toBe('/resources/cpr-soft-archived/'.$record->id);
});

it('falls back to the first-registered resource when the record is gone', function () {
    // Event references a model_id that no longer exists — matchesRecord can't
    // run, so it degrades to the first-match (current) behaviour, not a crash.
    cprLogEvent((int) $this->user->id, CPRItem::class, 99999, 'indexed');

    $response = $this->getJson('/martis/api/command-palette');

    $response->assertOk();
    $entry = collect($response->json('recent'))->firstWhere('key', 1);

    expect($entry)->not->toBeNull();
    expect($entry['url'])->toBe('/resources/cpr-pending/99999');
});

it('links a record to the routable resource when a headless one over the model is registered first', function () {
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(CPRHeadlessResource::class);
    $registry->register(CPRHeadPageResource::class);
    $registry->register(ActionEventResource::class);

    $record = CPRHeadItem::query()->create(['title' => 'Member']);
    cprLogEvent((int) $this->user->id, CPRHeadItem::class, (int) $record->id, 'created');

    $entry = collect($this->getJson('/martis/api/command-palette')->assertOk()->json('recent'))->firstWhere('key', 1);

    expect($entry)->not->toBeNull();
    // First-match would answer /resources/cpr-headless/..., a page that does not exist.
    expect($entry['url'])->toBe('/resources/cpr-head-page/'.$record->id);
    expect($entry['subtitle'])->toBe('cpr-head-page #'.$record->id);
});

it('keeps a headless resource when it is the only one over the model', function () {
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(CPRHeadlessResource::class);
    $registry->register(ActionEventResource::class);

    $record = CPRHeadItem::query()->create(['title' => 'Member']);
    cprLogEvent((int) $this->user->id, CPRHeadItem::class, (int) $record->id, 'created');

    $entry = collect($this->getJson('/martis/api/command-palette')->assertOk()->json('recent'))->firstWhere('key', 1);

    expect($entry['subtitle'])->toBe('cpr-headless #'.$record->id);
});

it('gates the Recent block on the routable audit-log resource, not a headless one registered first', function () {
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(CPRHeadlessAuditResource::class);
    $registry->register(CPRPendingResource::class);
    $registry->register(ActionEventResource::class);

    $record = CPRItem::query()->create(['title' => 'Draft', 'status' => 'pending']);
    cprLogEvent((int) $this->user->id, CPRItem::class, (int) $record->id, 'pending');

    $entry = collect($this->getJson('/martis/api/command-palette')->assertOk()->json('recent'))->firstWhere('key', 1);

    expect($entry)->not->toBeNull();
    expect($entry['url'])->toBe('/resources/cpr-pending/'.$record->id);
});
