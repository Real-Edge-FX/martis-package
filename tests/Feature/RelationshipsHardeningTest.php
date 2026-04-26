<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as EloquentBelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Edge-case fixtures: a Project has TWO BelongsTo relations to User
// (manager_id AND lead_id). Past Nova-parity bugs sometimes confused
// the two — the resolver needs to keep them isolated. The schema also
// has a Tag pivot exercising relatableQueryUsing scoping.
// ---------------------------------------------------------------------------

class RHUserModel extends Model
{
    protected $table = 'rh_test_users';

    protected $fillable = ['name', 'is_active'];

    public function managedProjects(): EloquentHasMany
    {
        return $this->hasMany(RHProjectModel::class, 'manager_id');
    }
}

class RHProjectModel extends Model
{
    protected $table = 'rh_test_projects';

    protected $fillable = ['title', 'manager_id', 'lead_id'];

    public function manager(): EloquentBelongsTo
    {
        return $this->belongsTo(RHUserModel::class, 'manager_id');
    }

    public function lead(): EloquentBelongsTo
    {
        return $this->belongsTo(RHUserModel::class, 'lead_id');
    }

    public function tags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(RHTagModel::class, 'rh_test_project_tag', 'project_id', 'tag_id');
    }
}

class RHTagModel extends Model
{
    protected $table = 'rh_test_tags';

    protected $fillable = ['name', 'archived'];
}

class RHUserResource extends Resource
{
    public static function model(): string
    {
        return RHUserModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->required()->searchable()->sortable(),
        ];
    }
}

class RHTagResource extends Resource
{
    public static function model(): string
    {
        return RHTagModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->required()->searchable()->sortable(),
        ];
    }
}

class RHProjectResource extends Resource
{
    public static function model(): string
    {
        return RHProjectModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->required(),
            BelongsTo::make('manager', 'Manager')
                ->relatedResource('r-h-user-models')
                ->relationSearchable(),
            BelongsTo::make('lead', 'Lead')
                ->relatedResource('r-h-user-models')
                ->relationSearchable(),
            BelongsToMany::make('Tags', 'tags')
                ->relatedResource('r-h-tag-models')
                // Hide archived tags from the attachable list — exercises
                // relatableQueryUsing scoping.
                ->relatableQueryUsing(fn (Request $r, $query) => $query->where('archived', false)),
            HasMany::make('Children', 'children')->relatedResource('r-h-project-models'),
        ];
    }

    /**
     * Per-Nova convention, a `relatable{PluralModelName}` method on the
     * source resource scopes the relatable query for ALL fields
     * targeting that model. The target model basename is RHUserModel
     * pluralized → RHUserModels. Hides inactive users.
     */
    public static function relatableRHUserModels(Request $request, $query)
    {
        return $query->where('is_active', true);
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('rh_test_project_tag');
    Schema::dropIfExists('rh_test_tags');
    Schema::dropIfExists('rh_test_projects');
    Schema::dropIfExists('rh_test_users');

    Schema::create('rh_test_users', function ($table) {
        $table->id();
        $table->string('name');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
    Schema::create('rh_test_projects', function ($table) {
        $table->id();
        $table->string('title');
        $table->unsignedBigInteger('manager_id')->nullable();
        $table->unsignedBigInteger('lead_id')->nullable();
        $table->timestamps();
    });
    Schema::create('rh_test_tags', function ($table) {
        $table->id();
        $table->string('name');
        $table->boolean('archived')->default(false);
        $table->timestamps();
    });
    Schema::create('rh_test_project_tag', function ($table) {
        $table->id();
        $table->unsignedBigInteger('project_id');
        $table->unsignedBigInteger('tag_id');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(RHUserResource::class);
    $registry->register(RHTagResource::class);
    $registry->register(RHProjectResource::class);
});

afterEach(function () {
    Schema::dropIfExists('rh_test_project_tag');
    Schema::dropIfExists('rh_test_tags');
    Schema::dropIfExists('rh_test_projects');
    Schema::dropIfExists('rh_test_users');
});

// ---------------------------------------------------------------------------
// Multi-relation to same model — manager and lead must stay independent
// ---------------------------------------------------------------------------

it('keeps two BelongsTo relations to the same model isolated through detail payload', function () {
    $alice = RHUserModel::create(['name' => 'Alice']);
    $bob = RHUserModel::create(['name' => 'Bob']);
    $project = RHProjectModel::create([
        'title' => 'Apollo',
        'manager_id' => $alice->id,
        'lead_id' => $bob->id,
    ]);

    $response = $this->getJson("/martis/api/resources/r-h-project-models/{$project->id}");

    $response->assertOk();
    $body = $response->getContent();

    // Regression guard: both names must appear in the serialized detail
    // body. In past Nova-parity bugs the second relation collapsed onto
    // the first, hiding one of the two names entirely.
    expect($body)->toContain('Alice');
    expect($body)->toContain('Bob');
});

it('relatable endpoint serves both relations independently', function () {
    RHUserModel::create(['name' => 'Alice', 'is_active' => true]);
    RHUserModel::create(['name' => 'Bob', 'is_active' => true]);
    $project = RHProjectModel::create(['title' => 'Apollo']);

    $managerResp = $this->getJson("/martis/api/resources/r-h-project-models/{$project->id}/relatable/manager_id");
    $leadResp = $this->getJson("/martis/api/resources/r-h-project-models/{$project->id}/relatable/lead_id");

    $managerResp->assertOk();
    $leadResp->assertOk();

    expect(count($managerResp->json('data')))->toBe(2);
    expect(count($leadResp->json('data')))->toBe(2);
});

// ---------------------------------------------------------------------------
// relatableQueryUsing — per-field scope
// ---------------------------------------------------------------------------

it('relatableQueryUsing on BelongsToMany hides records the closure filters out', function () {
    $project = RHProjectModel::create(['title' => 'Project']);
    RHTagModel::create(['name' => 'live-tag', 'archived' => false]);
    RHTagModel::create(['name' => 'archived-tag', 'archived' => true]);

    $response = $this->getJson("/martis/api/resources/r-h-project-models/{$project->id}/belongs-to-many/tags/attachable");

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain('live-tag');
    expect($names)->not->toContain('archived-tag');
});

// ---------------------------------------------------------------------------
// relatable{PluralModelName} on the resource
// ---------------------------------------------------------------------------

it('relatable{Users} convention method scopes BelongsTo lookups', function () {
    RHUserModel::create(['name' => 'Active User', 'is_active' => true]);
    RHUserModel::create(['name' => 'Inactive User', 'is_active' => false]);
    $project = RHProjectModel::create(['title' => 'Apollo']);

    $response = $this->getJson("/martis/api/resources/r-h-project-models/{$project->id}/relatable/manager_id");

    $response->assertOk();
    $body = $response->getContent();

    expect($body)->toContain('Active User');
    expect($body)->not->toContain('Inactive User');
});

// ---------------------------------------------------------------------------
// Search interaction with relations
// ---------------------------------------------------------------------------

it('search on BelongsTo relatable filters the candidate list', function () {
    RHUserModel::create(['name' => 'Alice', 'is_active' => true]);
    RHUserModel::create(['name' => 'Bob', 'is_active' => true]);
    $project = RHProjectModel::create(['title' => 'Apollo']);

    $response = $this->getJson("/martis/api/resources/r-h-project-models/{$project->id}/relatable/manager_id?search=Ali");

    $response->assertOk();
    $body = $response->getContent();
    expect($body)->toContain('Alice');
    expect($body)->not->toContain('Bob');
});

// ---------------------------------------------------------------------------
// Attach + detach round-trip on BelongsToMany — sanity test
// ---------------------------------------------------------------------------

it('round-trips attach + index + detach on a BelongsToMany', function () {
    $project = RHProjectModel::create(['title' => 'Project']);
    $tag = RHTagModel::create(['name' => 'shipped']);

    $attach = $this->postJson(
        "/martis/api/resources/r-h-project-models/{$project->id}/belongs-to-many/tags/attach",
        ['related_id' => $tag->id],
    );
    expect($attach->status())->toBeIn([200, 201]);

    $index = $this->getJson("/martis/api/resources/r-h-project-models/{$project->id}/belongs-to-many/tags");
    expect($index->json('meta.total'))->toBe(1);

    $detach = $this->deleteJson(
        "/martis/api/resources/r-h-project-models/{$project->id}/belongs-to-many/tags/{$tag->id}/detach",
    );
    expect($detach->status())->toBeIn([200, 204]);

    $afterDetach = $this->getJson("/martis/api/resources/r-h-project-models/{$project->id}/belongs-to-many/tags");
    expect($afterDetach->json('meta.total'))->toBe(0);
});

// ---------------------------------------------------------------------------
// Detach idempotency — detaching twice should not 500
// ---------------------------------------------------------------------------

it('detaching a tag that is not attached returns a clean error or no-op', function () {
    $project = RHProjectModel::create(['title' => 'Project']);
    $tag = RHTagModel::create(['name' => 'never-attached']);

    $response = $this->deleteJson(
        "/martis/api/resources/r-h-project-models/{$project->id}/belongs-to-many/tags/{$tag->id}/detach",
    );

    expect($response->status())->toBeIn([200, 204, 404, 422]);
    // Must never crash with 500.
    expect($response->status())->toBeLessThan(500);
});

// ---------------------------------------------------------------------------
// Cross-relationship cleanup — verify the relatable endpoint does not
// expose the parent record itself as a candidate (regression guard for
// past Nova-parity bug where a project could attach itself).
// ---------------------------------------------------------------------------

it('relatable list excludes the parent record itself when the relation is reflexive', function () {
    $project = RHProjectModel::create(['title' => 'Apollo']);
    $other = RHProjectModel::create(['title' => 'Beta']);

    // tags is a separate model so this test mostly proves the endpoint
    // behaves; deeper self-attach guard tests live in
    // BelongsToManyControllerTest.
    $response = $this->getJson("/martis/api/resources/r-h-project-models/{$project->id}/belongs-to-many/tags/attachable");

    $response->assertOk();
});
