<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Searchable;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
use Martis\Fields\Field;
use Martis\Fields\MorphToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A resource that confines its records with the declarative `scopes()` (a
 * tenant, here) is confined wherever its `indexQuery()` applies, in the
 * index's order: the global search (its results and its overflow count, on
 * the database and the Scout paths), the parent of a BelongsToMany panel,
 * and the parent of the pivot action and pivot field routes. Before v2.0
 * these built their query with `indexQuery()` alone, so the global search
 * listed another tenant's records and a pivot panel reached their parent.
 * On those surfaces and on an action run the hooks' clauses are grouped, so
 * an `orWhere()` in them cannot widen what the term, the key or the
 * selected ids pick.
 */

// ── Fixtures ────────────────────────────────────────────────────────────────

final class ScopeConfineContext
{
    /** The tenant the `tenant` scope keeps. */
    public static int $tenantId = 1;

    /** Whether the scope also keeps the shared records, with an `orWhere()`. */
    public static bool $orShared = false;

    /** @var list<string> The hooks the resource ran, in order. */
    public static array $calls = [];
}

class ScopeConfineProject extends Model
{
    protected $table = 'scope_confine_projects';

    protected $fillable = ['tenant_id', 'name', 'shared'];

    public function members(): EloquentBelongsToMany
    {
        return $this->belongsToMany(ScopeConfineMember::class, 'scope_confine_project_member', 'project_id', 'member_id')
            ->withPivot('role');
    }

    public function labels(): EloquentMorphToMany
    {
        return $this->morphToMany(ScopeConfineLabel::class, 'labelable', 'scope_confine_labelables', 'labelable_id', 'label_id')
            ->withPivot('role');
    }
}

/** The same table, searched through Laravel Scout (the collection engine). */
class ScopeConfineScoutProject extends ScopeConfineProject
{
    use Searchable;
}

class ScopeConfineMember extends Model
{
    protected $table = 'scope_confine_members';

    protected $fillable = ['name'];
}

class ScopeConfineLabel extends Model
{
    protected $table = 'scope_confine_labels';

    protected $fillable = ['name'];
}

class ScopeConfinePivotAction extends Action
{
    public ?string $name = 'Make Lead';

    protected bool $isPivotAction = true;

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        foreach ($models as $model) {
            $model->getRelation('pivot')->forceFill(['role' => 'lead'])->save();
        }

        return ActionResponse::message('Done.');
    }
}

/** A resource action: renames the records it runs on. */
class ScopeConfineRenameAction extends Action
{
    public ?string $name = 'Rename';

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        foreach ($models as $model) {
            $model->forceFill(['name' => 'Renamed'])->save();
        }

        return ActionResponse::message('Done.');
    }
}

/**
 * The pivot fields of both panels: a relation field among them has a picker
 * of its own, served by the panel.
 *
 * @return list<Field>
 */
function scopeConfinePivotFields(): array
{
    return [
        Text::make('role')->nullable(),
        BelongsTo::make('reviewer', 'Reviewer')->relatedResource('scope-confine-members')->titleAttribute('name')->nullable(),
    ];
}

class ScopeConfineProjectResource extends Resource
{
    public static function model(): string
    {
        return ScopeConfineProject::class;
    }

    public static function uriKey(): string
    {
        return 'scope-confine-projects';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public static function scopes(Request $request): array
    {
        return [
            'tenant' => function (Builder $query): Builder {
                ScopeConfineContext::$calls[] = 'scopes';

                $query->where('tenant_id', ScopeConfineContext::$tenantId);

                // Written as a scope often is: a top-level orWhere(), not a
                // grouped where(fn ...).
                return ScopeConfineContext::$orShared ? $query->orWhere('shared', true) : $query;
            },
        ];
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        ScopeConfineContext::$calls[] = 'indexQuery';

        return $query;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->searchable(),
            BelongsToMany::make('Members', 'members')
                ->relatedResource('scope-confine-members')
                ->fields(fn () => scopeConfinePivotFields()),
            MorphToMany::make('Labels', 'labels')
                ->relatedResource('scope-confine-labels')
                ->fields(fn () => scopeConfinePivotFields()),
        ];
    }

    public function actions(Request $request): array
    {
        return [ScopeConfinePivotAction::make(), ScopeConfineRenameAction::make()];
    }
}

class ScopeConfineScoutProjectResource extends ScopeConfineProjectResource
{
    public static function model(): string
    {
        return ScopeConfineScoutProject::class;
    }

    public static function uriKey(): string
    {
        return 'scope-confine-scout-projects';
    }
}

class ScopeConfineMemberResource extends Resource
{
    public static function model(): string
    {
        return ScopeConfineMember::class;
    }

    public static function uriKey(): string
    {
        return 'scope-confine-members';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class ScopeConfineLabelResource extends Resource
{
    public static function model(): string
    {
        return ScopeConfineLabel::class;
    }

    public static function uriKey(): string
    {
        return 'scope-confine-labels';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

// ── Setup ───────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    ScopeConfineContext::$tenantId = 1;
    ScopeConfineContext::$orShared = false;
    ScopeConfineContext::$calls = [];

    Schema::create('scope_confine_projects', function ($table) {
        $table->id();
        $table->unsignedInteger('tenant_id');
        $table->string('name');
        $table->boolean('shared')->default(false);
        $table->timestamps();
    });
    Schema::create('scope_confine_members', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('scope_confine_labels', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('scope_confine_project_member', function ($table) {
        $table->unsignedBigInteger('project_id');
        $table->unsignedBigInteger('member_id');
        $table->string('role')->nullable();
        $table->unsignedBigInteger('reviewer_id')->nullable();
    });
    Schema::create('scope_confine_labelables', function ($table) {
        $table->unsignedBigInteger('label_id');
        $table->morphs('labelable');
        $table->string('role')->nullable();
        $table->unsignedBigInteger('reviewer_id')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(ScopeConfineProjectResource::class);
    $registry->register(ScopeConfineMemberResource::class);
    $registry->register(ScopeConfineLabelResource::class);
});

/** Search one resource only, so each result group is that resource's. */
function scopeConfineSearchOnly(string $resourceClass): void
{
    config()->set('scout.driver', 'collection');

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register($resourceClass);
}

dataset('scope confine search paths', [
    'database LIKE' => [ScopeConfineProjectResource::class],
    'Scout' => [ScopeConfineScoutProjectResource::class],
]);

dataset('scope confine pivot panels', [
    'belongs-to-many' => ['belongs-to-many', 'members', ScopeConfineMember::class],
    'morph-to-many' => ['morph-to-many', 'labels', ScopeConfineLabel::class],
]);

// ── Global search ───────────────────────────────────────────────────────────

it('leaves the records its scopes() confine away out of the global search', function (string $resourceClass) {
    scopeConfineSearchOnly($resourceClass);
    ScopeConfineProject::create(['tenant_id' => 1, 'name' => 'Apollo mine']);
    ScopeConfineProject::create(['tenant_id' => 2, 'name' => 'Apollo theirs']);

    $results = $this->getJson('/martis/api/search?q=Apollo')->assertOk()->json('results');

    // The tenant's own record is still found: the scope narrows, it does not empty.
    expect($results)->toHaveCount(1)
        ->and(collect($results[0]['items'])->pluck('title')->all())->toBe(['Apollo mine']);
})->with('scope confine search paths');

it('counts only the records its scopes() keep in the global search total', function (string $resourceClass) {
    scopeConfineSearchOnly($resourceClass);
    config()->set('martis.search.default_limit', 2);
    ScopeConfineProject::create(['tenant_id' => 1, 'name' => 'Apollo one']);
    ScopeConfineProject::create(['tenant_id' => 1, 'name' => 'Apollo two']);
    ScopeConfineProject::create(['tenant_id' => 2, 'name' => 'Apollo three']);
    ScopeConfineProject::create(['tenant_id' => 2, 'name' => 'Apollo four']);
    ScopeConfineProject::create(['tenant_id' => 2, 'name' => 'Apollo five']);

    $group = $this->getJson('/martis/api/search?q=Apollo')->assertOk()->json('results.0');

    // The limit is reached, so the group carries the overflow count, which
    // must count the same records the items came from.
    expect(collect($group['items'])->pluck('title')->sort()->values()->all())->toBe(['Apollo one', 'Apollo two'])
        ->and($group['total'])->toBe(2);
})->with('scope confine search paths');

it('runs scopes() before indexQuery() in the global search, as the index does', function () {
    ScopeConfineProject::create(['tenant_id' => 1, 'name' => 'Apollo mine']);

    $this->getJson('/martis/api/resources/scope-confine-projects')->assertOk();
    $index = ScopeConfineContext::$calls;

    ScopeConfineContext::$calls = [];
    $this->getJson('/martis/api/search?q=Apollo')->assertOk();

    expect($index)->toBe(['scopes', 'indexQuery'])
        ->and(ScopeConfineContext::$calls)->toBe($index);
});

// ── BelongsToMany panel ─────────────────────────────────────────────────────

it('answers 404 on a BelongsToMany panel whose parent its scopes() confine away', function () {
    $theirs = ScopeConfineProject::create(['tenant_id' => 2, 'name' => 'Theirs']);
    $mine = ScopeConfineProject::create(['tenant_id' => 1, 'name' => 'Mine']);
    $member = ScopeConfineMember::create(['name' => 'Ada']);
    $base = '/martis/api/resources/scope-confine-projects';

    // Listed, attached to: a scoped-out parent answers as a missing one.
    $this->getJson("{$base}/{$theirs->id}/belongs-to-many/members")
        ->assertNotFound()
        ->assertJsonPath('message', 'Parent record not found.');
    $this->postJson("{$base}/{$theirs->id}/belongs-to-many/members/attach", ['related_id' => $member->id])
        ->assertNotFound();
    expect($theirs->members()->count())->toBe(0);

    // The tenant's own parent keeps its panel.
    $this->getJson("{$base}/{$mine->id}/belongs-to-many/members")->assertOk();
    $this->postJson("{$base}/{$mine->id}/belongs-to-many/members/attach", ['related_id' => $member->id])
        ->assertCreated();
    expect($mine->members()->count())->toBe(1);
});

// ── Pivot action and pivot field routes ─────────────────────────────────────

it('answers 404 on the pivot routes of a parent its scopes() confine away', function (string $type, string $relationship, string $relatedClass) {
    $theirs = ScopeConfineProject::create(['tenant_id' => 2, 'name' => 'Theirs']);
    $mine = ScopeConfineProject::create(['tenant_id' => 1, 'name' => 'Mine']);
    $related = $relatedClass::create(['name' => 'Attached']);
    $theirs->{$relationship}()->attach($related->id, ['role' => 'member']);
    $mine->{$relationship}()->attach($related->id, ['role' => 'member']);
    $base = '/martis/api/resources/scope-confine-projects';
    $role = fn (ScopeConfineProject $project) => $project->{$relationship}()->first()?->getRelation('pivot')->getAttribute('role');

    $this->getJson("{$base}/{$theirs->id}/{$type}/{$relationship}/actions")->assertNotFound();
    $this->getJson("{$base}/{$theirs->id}/{$type}/{$relationship}/actions/scope-confine-pivot-action/fields")->assertNotFound();
    $this->postJson("{$base}/{$theirs->id}/{$type}/{$relationship}/actions/scope-confine-pivot-action", ['resources' => [$related->id]])
        ->assertNotFound();
    // The picker of a relation field among the pivot fields (the attach modal).
    $this->getJson("{$base}/{$theirs->id}/{$type}/{$relationship}/pivot-fields/relatable/reviewer_id")->assertNotFound();
    expect($role($theirs))->toBe('member');

    // The tenant's own parent keeps its pivot actions and its picker.
    $this->getJson("{$base}/{$mine->id}/{$type}/{$relationship}/actions")
        ->assertOk()
        ->assertJsonPath('data.actions.0.uriKey', 'scope-confine-pivot-action');
    $this->getJson("{$base}/{$mine->id}/{$type}/{$relationship}/pivot-fields/relatable/reviewer_id")->assertOk();
    $this->postJson("{$base}/{$mine->id}/{$type}/{$relationship}/actions/scope-confine-pivot-action", ['resources' => [$related->id]])
        ->assertOk();
    expect($role($mine))->toBe('lead');
})->with('scope confine pivot panels');

// ── A scope written with orWhere() ──────────────────────────────────────────
//
// The hooks run as Eloquent runs a local scope, so what they add is one group
// and a constraint added after them (the term, the key, the selected ids)
// binds to all of it. Ungrouped, `tenant = 1 or shared = 1` followed by
// `and id = ?` reads `tenant = 1 or (shared = 1 and id = ?)`.

it('matches the search term against every record a scopes() orWhere() keeps', function (string $resourceClass) {
    scopeConfineSearchOnly($resourceClass);
    ScopeConfineContext::$orShared = true;
    ScopeConfineProject::create(['tenant_id' => 1, 'name' => 'Apollo mine']);
    ScopeConfineProject::create(['tenant_id' => 1, 'name' => 'Zeus mine']);
    ScopeConfineProject::create(['tenant_id' => 2, 'name' => 'Apollo shared', 'shared' => true]);
    ScopeConfineProject::create(['tenant_id' => 2, 'name' => 'Apollo theirs']);

    $group = $this->getJson('/martis/api/search?q=Apollo')->assertOk()->json('results.0');

    expect(collect($group['items'])->pluck('title')->sort()->values()->all())->toBe(['Apollo mine', 'Apollo shared']);
})->with('scope confine search paths');

it('resolves the parent the URL names under a scopes() orWhere()', function (string $type, string $relationship, string $relatedClass) {
    ScopeConfineContext::$orShared = true;
    $first = ScopeConfineProject::create(['tenant_id' => 1, 'name' => 'First']);
    $named = ScopeConfineProject::create(['tenant_id' => 1, 'name' => 'Named']);
    $firstRelated = $relatedClass::create(['name' => 'Of the first']);
    $namedRelated = $relatedClass::create(['name' => 'Of the named']);
    $first->{$relationship}()->attach($firstRelated->id, ['role' => 'member']);
    $named->{$relationship}()->attach($namedRelated->id, ['role' => 'member']);
    $base = "/martis/api/resources/scope-confine-projects/{$named->id}/{$type}/{$relationship}";

    // The pivot action runs on the named parent's row, not on the first
    // record the scope keeps.
    $this->postJson("{$base}/actions/scope-confine-pivot-action", ['resources' => [$namedRelated->id]])->assertOk();

    expect($named->{$relationship}()->first()?->getRelation('pivot')->getAttribute('role'))->toBe('lead')
        ->and($first->{$relationship}()->first()?->getRelation('pivot')->getAttribute('role'))->toBe('member');
})->with('scope confine pivot panels');

it('lists the BelongsToMany panel of the parent the URL names under a scopes() orWhere()', function () {
    ScopeConfineContext::$orShared = true;
    $first = ScopeConfineProject::create(['tenant_id' => 1, 'name' => 'First']);
    $named = ScopeConfineProject::create(['tenant_id' => 1, 'name' => 'Named']);
    $first->members()->attach(ScopeConfineMember::create(['name' => 'Ada'])->id);
    $named->members()->attach(ScopeConfineMember::create(['name' => 'Bob'])->id);

    $rows = $this->getJson("/martis/api/resources/scope-confine-projects/{$named->id}/belongs-to-many/members")
        ->assertOk()
        ->json('data');

    expect(collect($rows)->pluck('id')->all())->toBe([ScopeConfineMember::where('name', 'Bob')->value('id')]);
});

it('runs an action on the selected records only under a scopes() orWhere()', function () {
    ScopeConfineContext::$orShared = true;
    $other = ScopeConfineProject::create(['tenant_id' => 1, 'name' => 'Not selected']);
    $selected = ScopeConfineProject::create(['tenant_id' => 1, 'name' => 'Selected']);

    $this->postJson('/martis/api/resources/scope-confine-projects/actions/scope-confine-rename-action', ['resources' => [$selected->id]])
        ->assertOk();

    expect($selected->fresh()->name)->toBe('Renamed')
        ->and($other->fresh()->name)->toBe('Not selected');
});
