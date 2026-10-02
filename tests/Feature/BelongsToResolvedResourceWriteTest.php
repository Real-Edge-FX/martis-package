<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as EloquentBelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo as EloquentMorphTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsTo;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// A BelongsTo write is checked against the resource the field points at
// (F063). Without relatedResource() the resource is the one registered for
// the model of the relationship (Nova finds it too), so the target's
// relatableQuery(), its policies and the field's own closure apply to a
// field declared with no resource, instead of the write saving any id. A
// URI key no resource registers fails loudly, and a field that names no
// single resource is refused.
//
// Teams: 1 (tenant 1), 2 (tenant 1), 3 (tenant 2). The team resource's
// relatableQuery() keeps tenant 1.
// ---------------------------------------------------------------------------

class BtrTeam extends Model
{
    protected $table = 'btr_teams';

    protected $guarded = [];

    public $timestamps = false;
}

class BtrOrphan extends Model
{
    protected $table = 'btr_orphans';

    protected $guarded = [];

    public $timestamps = false;
}

class BtrTwice extends Model
{
    protected $table = 'btr_twices';

    protected $guarded = [];

    public $timestamps = false;
}

class BtrTask extends Model
{
    protected $table = 'btr_tasks';

    protected $guarded = [];

    public $timestamps = false;

    public function team(): EloquentBelongsTo
    {
        return $this->belongsTo(BtrTeam::class, 'team_id');
    }

    public function orphan(): EloquentBelongsTo
    {
        return $this->belongsTo(BtrOrphan::class, 'orphan_id');
    }

    public function twice(): EloquentBelongsTo
    {
        return $this->belongsTo(BtrTwice::class, 'twice_id');
    }

    public function subject(): EloquentMorphTo
    {
        return $this->morphTo();
    }

    public function notARelation(): string
    {
        return 'plain method';
    }
}

class BtrTeamResource extends Resource
{
    public static function model(): string
    {
        return BtrTeam::class;
    }

    public static function uriKey(): string
    {
        return 'btr-teams';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return $query->where('tenant_id', 1);
    }
}

class BtrTwiceResource extends Resource
{
    public static function model(): string
    {
        return BtrTwice::class;
    }

    public static function uriKey(): string
    {
        return 'btr-twices';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class BtrOtherTwiceResource extends BtrTwiceResource
{
    public static function uriKey(): string
    {
        return 'btr-other-twices';
    }
}

// The field declares no resource.
class BtrInferredTaskResource extends Resource
{
    public static function model(): string
    {
        return BtrTask::class;
    }

    public static function uriKey(): string
    {
        return 'btr-inferred-tasks';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            BelongsTo::make('team', 'Team')->nullable(),
            BelongsTo::make('orphan', 'Orphan')->nullable(),
            BelongsTo::make('twice', 'Twice')->nullable(),
            BelongsTo::make('ghost', 'Ghost')->nullable(),
        ];
    }
}

// A field that names a URI key nothing registers.
class BtrTypoTaskResource extends Resource
{
    public static function model(): string
    {
        return BtrTask::class;
    }

    public static function uriKey(): string
    {
        return 'btr-typo-tasks';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title'), BelongsTo::make('team', 'Team')->relatedResource('btr-teamz')->nullable()];
    }
}

// A field whose own closure narrows the picker further.
class BtrClosureTaskResource extends Resource
{
    public static function model(): string
    {
        return BtrTask::class;
    }

    public static function uriKey(): string
    {
        return 'btr-closure-tasks';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            BelongsTo::make('team', 'Team')
                ->relatableQueryUsing(fn (Request $request, Builder $query) => $query->where('name', '!=', 'Alpha'))
                ->nullable(),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['btr_tasks', 'btr_teams', 'btr_orphans', 'btr_twices'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('btr_teams', function ($t) {
        $t->id();
        $t->string('name');
        $t->unsignedBigInteger('tenant_id');
    });
    Schema::create('btr_orphans', function ($t) {
        $t->id();
    });
    Schema::create('btr_twices', function ($t) {
        $t->id();
        $t->string('name')->nullable();
    });
    Schema::create('btr_tasks', function ($t) {
        $t->id();
        $t->string('title');
        $t->unsignedBigInteger('team_id')->nullable();
        $t->unsignedBigInteger('orphan_id')->nullable();
        $t->unsignedBigInteger('twice_id')->nullable();
        $t->unsignedBigInteger('ghost_id')->nullable();
        $t->nullableMorphs('subject');
    });

    BtrTeam::query()->insert([
        ['id' => 1, 'name' => 'Alpha', 'tenant_id' => 1],
        ['id' => 2, 'name' => 'Beta', 'tenant_id' => 1],
        ['id' => 3, 'name' => 'Gamma', 'tenant_id' => 2],
    ]);
    BtrOrphan::query()->insert([['id' => 1]]);
    BtrTwice::query()->insert([['id' => 1, 'name' => 'x']]);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([BtrTeamResource::class, BtrTwiceResource::class, BtrOtherTwiceResource::class, BtrInferredTaskResource::class, BtrTypoTaskResource::class, BtrClosureTaskResource::class] as $class) {
        $registry->register($class);
    }
});

afterEach(function () {
    foreach (['btr_tasks', 'btr_teams', 'btr_orphans', 'btr_twices'] as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
});

// ---- inferred from the relationship's model --------------------------------

it('checks a BelongsTo with no relatedResource() against the resource of its relationship model', function () {
    // Team 3 is tenant 2: outside the team resource's relatableQuery().
    $this->postJson('/martis/api/resources/btr-inferred-tasks', ['title' => 'New', 'team_id' => 3])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'team_id')
        ->assertJsonPath('errors.0.code', 'relatable');

    expect(BtrTask::query()->count())->toBe(0);
});

it('accepts the id the inferred resource offers, as a raw id or an id map', function (mixed $value) {
    $this->postJson('/martis/api/resources/btr-inferred-tasks', ['title' => 'New', 'team_id' => $value])
        ->assertCreated();

    expect(BtrTask::query()->value('team_id'))->toBe(2);
})->with([2, '2', [['id' => 2]]]);

it('checks the inferred resource on update too', function () {
    $task = BtrTask::query()->create(['title' => 'Ship', 'team_id' => 1]);

    $this->putJson("/martis/api/resources/btr-inferred-tasks/{$task->id}", ['title' => 'Ship', 'team_id' => 3])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'team_id');

    expect($task->fresh()->team_id)->toBe(1);
});

it('applies the field relatableQueryUsing() closure of an inferred field', function () {
    $this->postJson('/martis/api/resources/btr-closure-tasks', ['title' => 'New', 'team_id' => 1])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'team_id');

    $this->postJson('/martis/api/resources/btr-closure-tasks', ['title' => 'New', 'team_id' => 2])->assertCreated();
});

it('accepts an empty value without resolving a resource', function () {
    $this->postJson('/martis/api/resources/btr-inferred-tasks', ['title' => 'New', 'team_id' => null, 'orphan_id' => null, 'ghost_id' => ''])
        ->assertCreated();
});

// ---- nothing to check against: refused -------------------------------------

it('refuses a value when the relationship model has no registered resource', function () {
    $this->postJson('/martis/api/resources/btr-inferred-tasks', ['title' => 'New', 'orphan_id' => 1])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'orphan_id')
        ->assertJsonPath('errors.0.message', 'The Orphan has no related resource to check the selected record against. Declare relatedResource() on the field.');

    expect(BtrTask::query()->count())->toBe(0);
});

it('refuses a value when several resources are registered for the relationship model', function () {
    $this->postJson('/martis/api/resources/btr-inferred-tasks', ['title' => 'New', 'twice_id' => 1])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'twice_id');

    expect(BtrTask::query()->count())->toBe(0);
});

it('refuses a value for a field whose relationship the record does not have', function () {
    $this->postJson('/martis/api/resources/btr-inferred-tasks', ['title' => 'New', 'ghost_id' => 1])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'ghost_id');

    expect(BtrTask::query()->count())->toBe(0);
});

it('translates the refusal', function () {
    foreach (['pt_PT', 'pt_BR'] as $locale) {
        app()->setLocale($locale);

        $message = $this->postJson('/martis/api/resources/btr-inferred-tasks', ['title' => 'New', 'orphan_id' => 1])
            ->assertStatus(422)
            ->json('errors.0.message');

        expect($message)->toContain('relatedResource()')->not->toContain('martis::');
    }
});

// ---- a URI key nothing registers -------------------------------------------

it('fails loudly, naming the field and the key, when relatedResource() names an unregistered resource', function () {
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/martis/api/resources/btr-typo-tasks', ['title' => 'New', 'team_id' => 1]))
        ->toThrow(InvalidArgumentException::class, "'team_id'");

    expect(BtrTask::query()->count())->toBe(0);
});

it('names the unregistered key in the exception', function () {
    $this->withoutExceptionHandling();

    try {
        $this->postJson('/martis/api/resources/btr-typo-tasks', ['title' => 'New', 'team_id' => 1]);
        $this->fail('The write of a field with an unregistered relatedResource() did not fail.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('btr-teamz')->toContain('team_id')->toContain('relatedResource()');
    }
});

it('does not fail for an unregistered key while the value is empty', function () {
    $this->postJson('/martis/api/resources/btr-typo-tasks', ['title' => 'New', 'team_id' => null])->assertCreated();
});

// ---- the field and the registry --------------------------------------------

it('resolves the resource a field points at', function () {
    $source = new BtrTask;

    expect(BelongsTo::make('team')->relatedResourceClass($source))->toBe(BtrTeamResource::class)
        ->and(BelongsTo::make('team')->relatedResource('btr-twices')->relatedResourceClass($source))->toBe(BtrTwiceResource::class)
        ->and(BelongsTo::make('orphan')->relatedResourceClass($source))->toBeNull()
        ->and(BelongsTo::make('twice')->relatedResourceClass($source))->toBeNull()
        ->and(BelongsTo::make('ghost')->relatedResourceClass($source))->toBeNull()
        ->and(BelongsTo::make('subject')->relatedResourceClass($source))->toBeNull()
        ->and(BelongsTo::make('not_a_relation')->relatedResourceClass($source))->toBeNull()
        ->and(BelongsTo::make('team')->relatedResourceClass(null))->toBeNull();
});

it('lists the resources registered for a model', function () {
    $registry = app(ResourceRegistry::class);

    expect($registry->forModel(BtrTeam::class))->toBe([BtrTeamResource::class])
        ->and($registry->forModel('\\'.BtrTwice::class))->toBe([BtrTwiceResource::class, BtrOtherTwiceResource::class])
        ->and($registry->forModel(BtrOrphan::class))->toBe([]);
});
