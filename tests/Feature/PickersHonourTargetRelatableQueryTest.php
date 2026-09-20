<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as EloquentBelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Contracts\FieldContract;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
use Martis\Fields\MorphToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// A resource that fences itself with relatableQuery() must be fenced on EVERY
// picker that targets it, not only on the BelongsTo dropdown:
//
//   * the BelongsToMany / MorphToMany "attachable" endpoint started from a bare
//     {Model}::query() and only applied the field's relatableQueryUsing()
//     closure, so the target's relatableQuery() never ran there;
//   * RelationshipQueryResolver::resolve() was an if/else: a source-side
//     relatable{PluralModelName}() REPLACED the target's relatableQuery()
//     instead of composing with it, so any source resource could silently
//     reopen a fence another resource had confined by hand.
//
// The model here is the tenancy boundary itself (no global scope can carry
// the fence), so relatableQuery() is the only fence it has.
// ---------------------------------------------------------------------------

class PTQUser extends Model
{
    protected $table = 'ptq_users';

    protected $guarded = [];

    public $timestamps = false;
}

class PTQTeam extends Model
{
    protected $table = 'ptq_teams';

    protected $guarded = [];

    public $timestamps = false;

    public function members(): EloquentBelongsToMany
    {
        return $this->belongsToMany(PTQUser::class, 'ptq_team_user', 'team_id', 'user_id');
    }
}

class PTQTask extends Model
{
    protected $table = 'ptq_tasks';

    protected $guarded = [];

    public $timestamps = false;

    public function owner(): EloquentBelongsTo
    {
        return $this->belongsTo(PTQUser::class, 'owner_id');
    }
}

class PTQTag extends Model
{
    protected $table = 'ptq_tags';

    protected $guarded = [];

    public $timestamps = false;
}

class PTQPost extends Model
{
    protected $table = 'ptq_posts';

    protected $guarded = [];

    public $timestamps = false;

    public function tags(): EloquentMorphToMany
    {
        return $this->morphToMany(PTQTag::class, 'taggable', 'ptq_taggables', null, 'tag_id');
    }
}

// The hand-confined target: index AND pickers fenced to tenant 1.
class PTQUserResource extends Resource
{
    public static function model(): string
    {
        return PTQUser::class;
    }

    public static function uriKey(): string
    {
        return 'ptq-users';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->searchable()];
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('tenant_id', 1);
    }

    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return $query->where('tenant_id', 1);
    }
}

class PTQTagResource extends Resource
{
    public static function model(): string
    {
        return PTQTag::class;
    }

    public static function uriKey(): string
    {
        return 'ptq-tags';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return $query->where('is_public', true);
    }
}

// Source #1: a plain BelongsToMany with NO closure — relies on the target fence.
class PTQTeamResource extends Resource
{
    public static function model(): string
    {
        return PTQTeam::class;
    }

    public static function uriKey(): string
    {
        return 'ptq-teams';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsToMany::make('Members', 'members')->relatedResource('ptq-users'),
        ];
    }
}

// Source #2: same target, plus a field closure that narrows further.
class PTQTeamWithClosureResource extends PTQTeamResource
{
    public static function uriKey(): string
    {
        return 'ptq-teams-closure';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsToMany::make('Members', 'members')
                ->relatedResource('ptq-users')
                ->relatableQueryUsing(fn (Request $r, $query) => $query->where('is_active', true)),
        ];
    }
}

// Source #3: declares relatable{PluralModelName}() for its own reasons. Under
// replace semantics this silently dropped the target's tenant fence.
class PTQTeamWithOverrideResource extends PTQTeamResource
{
    public static function uriKey(): string
    {
        return 'ptq-teams-override';
    }

    public static function relatablePTQUsers(Request $request, Builder $query, ?FieldContract $field = null): Builder
    {
        return $query->where('is_active', true);
    }
}

// BelongsTo source with the same override — the resolver path.
class PTQTaskResource extends Resource
{
    public static function model(): string
    {
        return PTQTask::class;
    }

    public static function uriKey(): string
    {
        return 'ptq-tasks';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            BelongsTo::make('owner', 'Owner')->relatedResource('ptq-users'),
        ];
    }

    public static function relatablePTQUsers(Request $request, Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

class PTQPostResource extends Resource
{
    public static function model(): string
    {
        return PTQPost::class;
    }

    public static function uriKey(): string
    {
        return 'ptq-posts';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            MorphToMany::make('Tags', 'tags')->relatedResource('ptq-tags'),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['ptq_taggables', 'ptq_tags', 'ptq_posts', 'ptq_team_user', 'ptq_tasks', 'ptq_teams', 'ptq_users'] as $t) {
        Schema::dropIfExists($t);
    }
    Schema::create('ptq_users', function ($t) {
        $t->id();
        $t->string('name');
        $t->unsignedBigInteger('tenant_id');
        $t->boolean('is_active')->default(true);
    });
    Schema::create('ptq_teams', function ($t) {
        $t->id();
        $t->string('name');
    });
    Schema::create('ptq_tasks', function ($t) {
        $t->id();
        $t->string('title');
        $t->unsignedBigInteger('owner_id')->nullable();
    });
    Schema::create('ptq_team_user', function ($t) {
        $t->id();
        $t->unsignedBigInteger('team_id');
        $t->unsignedBigInteger('user_id');
    });
    Schema::create('ptq_tags', function ($t) {
        $t->id();
        $t->string('name');
        $t->boolean('is_public')->default(true);
    });
    Schema::create('ptq_posts', function ($t) {
        $t->id();
        $t->string('title');
    });
    Schema::create('ptq_taggables', function ($t) {
        $t->id();
        $t->unsignedBigInteger('tag_id');
        $t->morphs('taggable');
    });

    // Tenant 1: Ana (active), Bruno (inactive). Tenant 2: Carla (active).
    PTQUser::query()->insert([
        ['id' => 1, 'name' => 'Ana', 'tenant_id' => 1, 'is_active' => true],
        ['id' => 2, 'name' => 'Bruno', 'tenant_id' => 1, 'is_active' => false],
        ['id' => 3, 'name' => 'Carla', 'tenant_id' => 2, 'is_active' => true],
    ]);
    PTQTag::query()->insert([
        ['id' => 1, 'name' => 'public', 'is_public' => true],
        ['id' => 2, 'name' => 'private', 'is_public' => false],
    ]);
    $this->team = PTQTeam::query()->create(['name' => 'Core']);
    $this->task = PTQTask::query()->create(['title' => 'Ship']);
    $this->post = PTQPost::query()->create(['title' => 'Hello']);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([
        PTQUserResource::class, PTQTagResource::class, PTQTeamResource::class,
        PTQTeamWithClosureResource::class, PTQTeamWithOverrideResource::class,
        PTQTaskResource::class, PTQPostResource::class,
    ] as $class) {
        $registry->register($class);
    }
});

afterEach(function () {
    foreach (['ptq_taggables', 'ptq_tags', 'ptq_posts', 'ptq_team_user', 'ptq_tasks', 'ptq_teams', 'ptq_users'] as $t) {
        Schema::dropIfExists($t);
    }
    app(ResourceRegistry::class)->flush();
});

function ptqNames(\Illuminate\Testing\TestResponse $response): array
{
    $names = collect($response->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

    return $names;
}

it('fences the BelongsToMany attachable picker with the target relatableQuery() even without a field closure', function () {
    $response = $this->getJson("/martis/api/resources/ptq-teams/{$this->team->id}/belongs-to-many/members/attachable");

    expect(ptqNames($response))->toBe(['Ana', 'Bruno']);
});

it('lets a field closure narrow the already-fenced BelongsToMany attachable picker', function () {
    $response = $this->getJson("/martis/api/resources/ptq-teams-closure/{$this->team->id}/belongs-to-many/members/attachable");

    expect(ptqNames($response))->toBe(['Ana']);
});

it('fences the MorphToMany attachable picker with the target relatableQuery()', function () {
    $response = $this->getJson("/martis/api/resources/ptq-posts/{$this->post->id}/morph-to-many/tags/attachable");

    expect(collect($response->assertOk()->json('data'))->pluck('name')->all())->toBe(['public']);
});

it('keeps the target fence when a source declares relatable{PluralModelName}() (attach picker)', function () {
    $response = $this->getJson("/martis/api/resources/ptq-teams-override/{$this->team->id}/belongs-to-many/members/attachable");

    // Composed: tenant fence (target) AND active (source override). Carla
    // (tenant 2, active) must never reappear through the override.
    expect(ptqNames($response))->toBe(['Ana']);
});

it('keeps the target fence when a source declares relatable{PluralModelName}() (BelongsTo picker)', function () {
    $response = $this->getJson("/martis/api/resources/ptq-tasks/{$this->task->id}/relatable/owner_id");

    expect(ptqNames($response))->toBe(['Ana']);
});

it('still fences the context-free relatable form with the target relatableQuery()', function () {
    $response = $this->getJson('/martis/api/resources/_/_/relatable/owner_id?related_resource=ptq-users');

    expect(ptqNames($response))->toBe(['Ana', 'Bruno']);
});

it('keeps search inside the fence on the attachable picker', function () {
    $response = $this->getJson("/martis/api/resources/ptq-teams/{$this->team->id}/belongs-to-many/members/attachable?search=Carla");

    expect(ptqNames($response))->toBe([]);
});
