<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as EloquentBelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A BelongsTo among the pivot fields of a many-to-many panel, with no
 * relatedResource(): the relationship it names (`approver`) lives on the pivot
 * model, not on the parent record the panel belongs to. The Relatable rule
 * read it from the parent, so it checked the write against the resource of a
 * same-named relation of the PARENT (a different model), or refused every
 * write as unresolved when the parent has none.
 *
 * People: 1 (tenant 1, the pivot's picker offers it), 2 (tenant 2, it does not).
 * Decoys: the parent's own `approver` relation points at them; every decoy is open.
 */

class PbrProject extends Model
{
    protected $table = 'pbr_projects';

    protected $guarded = [];

    public $timestamps = false;

    public function members(): EloquentBelongsToMany
    {
        return $this->belongsToMany(PbrMember::class, 'pbr_assignments', 'project_id', 'member_id')
            ->using(PbrAssignment::class)
            ->withPivot('approver_id');
    }

    /** The parent's own `approver`: another model than the pivot's. */
    public function approver(): EloquentBelongsTo
    {
        return $this->belongsTo(PbrDecoy::class, 'decoy_id');
    }
}

class PbrMember extends Model
{
    protected $table = 'pbr_members';

    protected $guarded = [];

    public $timestamps = false;
}

class PbrPerson extends Model
{
    protected $table = 'pbr_people';

    protected $guarded = [];

    public $timestamps = false;
}

class PbrDecoy extends Model
{
    protected $table = 'pbr_decoys';

    protected $guarded = [];

    public $timestamps = false;
}

class PbrAssignment extends Pivot
{
    protected $table = 'pbr_assignments';

    public $incrementing = true;

    public $timestamps = false;

    public function approver(): EloquentBelongsTo
    {
        return $this->belongsTo(PbrPerson::class, 'approver_id');
    }

    /** A relation the pivot has and no other model has. */
    public function reviewer(): EloquentBelongsTo
    {
        return $this->belongsTo(PbrPerson::class, 'reviewer_id');
    }
}

class PbrPersonResource extends Resource
{
    public static function model(): string
    {
        return PbrPerson::class;
    }

    public static function uriKey(): string
    {
        return 'pbr-people';
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

class PbrDecoyResource extends Resource
{
    public static function model(): string
    {
        return PbrDecoy::class;
    }

    public static function uriKey(): string
    {
        return 'pbr-decoys';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class PbrMemberResource extends Resource
{
    public static function model(): string
    {
        return PbrMember::class;
    }

    public static function uriKey(): string
    {
        return 'pbr-members';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class PbrProjectResource extends Resource
{
    public static function model(): string
    {
        return PbrProject::class;
    }

    public static function uriKey(): string
    {
        return 'pbr-projects';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsToMany::make('Members', 'members')->relatedResource('pbr-members')
                ->fields(fn () => [
                    // No relatedResource(): the relationship is the pivot model's.
                    BelongsTo::make('approver', 'Approver')->nullable(),
                    // A relation only the pivot has: nothing on the parent to confuse it with.
                    BelongsTo::make('reviewer', 'Reviewer')->nullable(),
                    // Nothing on the pivot either: refused as unresolved.
                    BelongsTo::make('ghost', 'Ghost')->nullable(),
                ]),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['pbr_assignments', 'pbr_projects', 'pbr_members', 'pbr_people', 'pbr_decoys'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('pbr_projects', function ($t) {
        $t->id();
        $t->string('name');
        $t->unsignedBigInteger('decoy_id')->nullable();
    });
    Schema::create('pbr_members', fn ($t) => [$t->id(), $t->string('name')]);
    Schema::create('pbr_people', fn ($t) => [$t->id(), $t->string('name'), $t->unsignedBigInteger('tenant_id')]);
    Schema::create('pbr_decoys', fn ($t) => [$t->id(), $t->string('name')]);
    Schema::create('pbr_assignments', function ($t) {
        $t->id();
        $t->unsignedBigInteger('project_id');
        $t->unsignedBigInteger('member_id');
        $t->unsignedBigInteger('approver_id')->nullable();
        $t->unsignedBigInteger('reviewer_id')->nullable();
        $t->unsignedBigInteger('ghost_id')->nullable();
    });

    PbrPerson::query()->insert([['id' => 1, 'name' => 'Ann', 'tenant_id' => 1], ['id' => 2, 'name' => 'Bob', 'tenant_id' => 2]]);
    PbrDecoy::query()->insert([['id' => 1, 'name' => 'Open'], ['id' => 2, 'name' => 'Open too']]);
    PbrMember::query()->insert([['id' => 1, 'name' => 'M']]);
    $this->project = PbrProject::query()->create(['name' => 'P']);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([PbrPersonResource::class, PbrDecoyResource::class, PbrMemberResource::class, PbrProjectResource::class] as $class) {
        $registry->register($class);
    }

    $this->attach = "/martis/api/resources/pbr-projects/{$this->project->id}/belongs-to-many/members/attach";
});

afterEach(function () {
    foreach (['pbr_assignments', 'pbr_projects', 'pbr_members', 'pbr_people', 'pbr_decoys'] as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
});

it('checks a pivot BelongsTo against the resource of the pivot model\'s relationship, not the parent\'s same-named one', function () {
    // Person 2 is tenant 2: outside the people resource's relatableQuery(). The parent's own
    // `approver` points at open decoys, which would let id 2 through if it were the one checked.
    $this->postJson($this->attach, ['related_id' => 1, 'approver_id' => 2])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'approver_id')
        ->assertJsonPath('errors.0.code', 'relatable');

    expect(DB::table('pbr_assignments')->count())->toBe(0);
});

it('accepts the id the pivot relationship\'s resource offers, on attach and on pivot update', function () {
    $this->postJson($this->attach, ['related_id' => 1, 'approver_id' => 1])->assertCreated();
    expect(DB::table('pbr_assignments')->value('approver_id'))->toBe(1);

    $url = "/martis/api/resources/pbr-projects/{$this->project->id}/belongs-to-many/members/1/pivot";
    $this->putJson($url, ['approver_id' => 2])->assertStatus(422)->assertJsonPath('errors.0.field', 'approver_id');
    $this->putJson($url, ['approver_id' => 1])->assertOk();
});

it('resolves a relation only the pivot model has', function () {
    $this->postJson($this->attach, ['related_id' => 1, 'reviewer_id' => 1])->assertCreated();
    expect(DB::table('pbr_assignments')->value('reviewer_id'))->toBe(1);

    DB::table('pbr_assignments')->delete();
    $this->postJson($this->attach, ['related_id' => 1, 'reviewer_id' => 2])->assertStatus(422)->assertJsonPath('errors.0.field', 'reviewer_id');
});

it('refuses a pivot BelongsTo whose relationship neither the pivot nor the parent has', function () {
    $this->postJson($this->attach, ['related_id' => 1, 'ghost_id' => 1])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'ghost_id');

    expect(DB::table('pbr_assignments')->count())->toBe(0);
});

it('still accepts an empty pivot BelongsTo', function () {
    $this->postJson($this->attach, ['related_id' => 1, 'approver_id' => null, 'reviewer_id' => null])->assertCreated();
});
