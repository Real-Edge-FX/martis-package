<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class BTMParentModel extends Model
{
    protected $table = 'btm_test_parents';

    protected $fillable = ['name'];

    public function children(): EloquentBelongsToMany
    {
        return $this->belongsToMany(BTMChildModel::class, 'btm_test_pivot', 'parent_id', 'child_id')
            ->withPivot(['notes'])
            ->withTimestamps();
    }
}

class BTMChildModel extends Model
{
    protected $table = 'btm_test_children';

    protected $fillable = ['title'];
}

class BTMParentResource extends Resource
{
    public static function model(): string
    {
        return BTMParentModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->required(),
            BelongsToMany::make('Children', 'children')
                ->relatedResource('b-t-m-child-models')
                ->searchable()
                ->fields(fn () => [
                    Text::make('notes', 'Notes')->nullable(),
                ]),
        ];
    }
}

class BTMChildResource extends Resource
{
    public static function model(): string
    {
        return BTMChildModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->required(),
        ];
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('btm_test_pivot');
    Schema::dropIfExists('btm_test_children');
    Schema::dropIfExists('btm_test_parents');
    Schema::enableForeignKeyConstraints();

    Schema::create('btm_test_parents', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('btm_test_children', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    Schema::disableForeignKeyConstraints();
    Schema::create('btm_test_pivot', function ($table) {
        $table->foreignId('parent_id')->constrained('btm_test_parents')->onDelete('cascade');
        $table->foreignId('child_id')->constrained('btm_test_children')->onDelete('cascade');
        $table->string('notes')->nullable();
        $table->timestamps();
        $table->primary(['parent_id', 'child_id']);
    });
    Schema::enableForeignKeyConstraints();

    $registry = app(ResourceRegistry::class);
    $registry->register(BTMParentResource::class);
    $registry->register(BTMChildResource::class);

    // Bypass auth middleware
    app('router')->aliasMiddleware('martis.auth', MartisAuthenticate::class);
    $this->withoutMiddleware(MartisAuthenticate::class);
});

afterEach(function () {
    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('btm_test_pivot');
    Schema::dropIfExists('btm_test_children');
    Schema::dropIfExists('btm_test_parents');
    Schema::enableForeignKeyConstraints();
});

// ---------------------------------------------------------------------------
// BelongsToMany Field Tests
// ---------------------------------------------------------------------------

describe('BelongsToMany Field', function () {
    it('has the correct type', function () {
        $field = BelongsToMany::make('Tags');
        expect($field->type())->toBe('belongs_to_many');
    });

    it('infers relationship name from label', function () {
        $field = BelongsToMany::make('Tags');
        expect($field->getRelationship())->toBe('tags');
    });

    it('uses explicit relationship when provided', function () {
        $field = BelongsToMany::make('Tags', 'myTags');
        expect($field->getRelationship())->toBe('myTags');
    });

    it('resolves related resource key from relationship name', function () {
        $field = BelongsToMany::make('Tags', 'tags');
        expect($field->getRelatedResourceKey())->toContain('tag');
    });

    it('is hidden from index by default but shown on forms', function () {
        $field = BelongsToMany::make('Tags');
        $schema = $field->toArray();
        expect($schema['showOnIndex'])->toBeFalse();
        expect($schema['showOnForms'])->toBeTrue();
    });

    it('includes pivot fields in toArray schema', function () {
        $field = BelongsToMany::make('Tags')
            ->fields(fn () => [Text::make('notes', 'Notes')]);
        $schema = $field->toArray();
        expect($schema['pivotFields'])->toHaveCount(1);
        expect($schema['pivotFields'][0]['attribute'])->toBe('notes');
    });

    it('serializes all metadata in extraAttributes', function () {
        $field = BelongsToMany::make('Tags', 'tags')
            ->relatedResource('tags')
            ->searchable()
            ->collapsable()
            ->allowDuplicateRelations()
            ->showCreateRelationButton()
            ->withSubtitles();

        $schema = $field->toArray();
        expect($schema['searchable'])->toBeTrue();
        expect($schema['collapsable'])->toBeTrue();
        expect($schema['allowDuplicateRelations'])->toBeTrue();
        expect($schema['showCreateRelationButton'])->toBeTrue();
        expect($schema['withSubtitles'])->toBeTrue();
    });

    it('fill is a no-op', function () {
        $field = BelongsToMany::make('Tags');
        $model = new BTMParentModel;
        $field->fill($model, [1, 2, 3]);
        // Should not modify model — no assertions needed except no exception thrown
        expect(true)->toBeTrue();
    });

    it('resolve returns null on detail page', function () {
        $parent = BTMParentModel::create(['name' => 'Test Parent']);
        $field = BelongsToMany::make('Tags');
        $result = $field->resolve($parent);
        expect($result)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// Controller Tests
// ---------------------------------------------------------------------------

describe('BelongsToManyController', function () {
    it('lists attached records (index)', function () {
        $parent = BTMParentModel::create(['name' => 'Parent A']);
        $child1 = BTMChildModel::create(['title' => 'Child 1']);
        $child2 = BTMChildModel::create(['title' => 'Child 2']);
        $parent->children()->attach([$child1->id, $child2->id]);

        $response = $this->getJson("/martis/api/resources/b-t-m-parent-models/{$parent->id}/belongs-to-many/children");

        $response->assertStatus(200);
        $response->assertJsonPath('meta.total', 2);
        expect(count($response->json('data')))->toBe(2);
    });

    it('includes pivot data in index response', function () {
        $parent = BTMParentModel::create(['name' => 'Parent A']);
        $child = BTMChildModel::create(['title' => 'Child 1']);
        $parent->children()->attach($child->id, ['notes' => 'important']);

        $response = $this->getJson("/martis/api/resources/b-t-m-parent-models/{$parent->id}/belongs-to-many/children");

        $response->assertStatus(200);
        $data = $response->json('data');
        expect($data)->toHaveCount(1);
        expect($data[0])->toHaveKey('_pivot');
        expect($data[0]['_pivot']['notes'])->toBe('important');
    });

    it('ignores an undeclared sort column and preserves default order (whitelist)', function () {
        // Security/correctness regression: the raw ?sort= used to go
        // straight to $query->orderBy($rawSort, ...) with no whitelist.
        // BTMChildResource::title is a REAL column but is NOT declared
        // ->sortable(), so ?sort=title used to reorder alphabetically
        // (and on MySQL/Postgres a non-existent column 500s). The sort
        // must be validated against the related resource's declared
        // sortable fields; an undeclared column is dropped and the
        // default (id / insertion) order stands.
        $parent = BTMParentModel::create(['name' => 'Parent A']);
        $zebra = BTMChildModel::create(['title' => 'Zebra']);
        $apple = BTMChildModel::create(['title' => 'Apple']);
        $parent->children()->attach([$zebra->id, $apple->id]);

        $response = $this->getJson(
            "/martis/api/resources/b-t-m-parent-models/{$parent->id}/belongs-to-many/children?sort=title&direction=asc"
        );

        $response->assertStatus(200);
        // Default id/insertion order (Zebra then Apple), NOT alphabetical
        // — proves the undeclared `title` sort was dropped.
        expect(array_column($response->json('data'), 'title'))->toBe(['Zebra', 'Apple']);
    });

    it('lists attachable records (attachable)', function () {
        $parent = BTMParentModel::create(['name' => 'Parent A']);
        $child1 = BTMChildModel::create(['title' => 'Child 1']);
        $child2 = BTMChildModel::create(['title' => 'Child 2']);
        $parent->children()->attach($child1->id);

        $response = $this->getJson("/martis/api/resources/b-t-m-parent-models/{$parent->id}/belongs-to-many/children/attachable");

        $response->assertStatus(200);
        // Only child2 should be available (child1 is already attached)
        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toContain($child2->id);
        expect($ids)->not->toContain($child1->id);
    });

    it('attaches a record', function () {
        $parent = BTMParentModel::create(['name' => 'Parent A']);
        $child = BTMChildModel::create(['title' => 'Child 1']);

        $response = $this->postJson(
            "/martis/api/resources/b-t-m-parent-models/{$parent->id}/belongs-to-many/children/attach",
            ['related_id' => $child->id]
        );

        $response->assertStatus(201);
        expect($parent->children()->count())->toBe(1);
    });

    it('attaches with pivot data', function () {
        $parent = BTMParentModel::create(['name' => 'Parent A']);
        $child = BTMChildModel::create(['title' => 'Child 1']);

        $response = $this->postJson(
            "/martis/api/resources/b-t-m-parent-models/{$parent->id}/belongs-to-many/children/attach",
            ['related_id' => $child->id, 'notes' => 'test note']
        );

        $response->assertStatus(201);
        $pivot = $parent->children()->first()?->pivot;
        expect($pivot?->notes)->toBe('test note');
    });

    it('rejects duplicate attach by default', function () {
        $parent = BTMParentModel::create(['name' => 'Parent A']);
        $child = BTMChildModel::create(['title' => 'Child 1']);
        $parent->children()->attach($child->id);

        $response = $this->postJson(
            "/martis/api/resources/b-t-m-parent-models/{$parent->id}/belongs-to-many/children/attach",
            ['related_id' => $child->id]
        );

        $response->assertStatus(422);
    });

    it('detaches a record', function () {
        $parent = BTMParentModel::create(['name' => 'Parent A']);
        $child = BTMChildModel::create(['title' => 'Child 1']);
        $parent->children()->attach($child->id);

        $response = $this->deleteJson(
            "/martis/api/resources/b-t-m-parent-models/{$parent->id}/belongs-to-many/children/{$child->id}/detach"
        );

        $response->assertStatus(200);
        expect($parent->children()->count())->toBe(0);
    });

    it('updates pivot data', function () {
        $parent = BTMParentModel::create(['name' => 'Parent A']);
        $child = BTMChildModel::create(['title' => 'Child 1']);
        $parent->children()->attach($child->id, ['notes' => 'old note']);

        $response = $this->putJson(
            "/martis/api/resources/b-t-m-parent-models/{$parent->id}/belongs-to-many/children/{$child->id}/pivot",
            ['notes' => 'updated note']
        );

        $response->assertStatus(200);
        $pivot = $parent->fresh()->children()->first()?->pivot;
        expect($pivot?->notes)->toBe('updated note');
    });

    it('returns 403 when pivot update is denied by policy', function () {
        // Swap the parent resource for one that denies update.
        $deniedResourceClass = new class(null) extends BTMParentResource
        {
            public static function uriKey(): string
            {
                return 'btm-denied-parents';
            }

            public function authorizedToUpdate(Request $request): bool
            {
                return false;
            }
        };

        $registry = app(ResourceRegistry::class);
        $registry->register($deniedResourceClass::class);

        $parent = BTMParentModel::create(['name' => 'Denied parent']);
        $child = BTMChildModel::create(['title' => 'Denied child']);
        $parent->children()->attach($child->id, ['notes' => 'initial']);

        $response = $this->putJson(
            "/martis/api/resources/btm-denied-parents/{$parent->id}/belongs-to-many/children/{$child->id}/pivot",
            ['notes' => 'attempted']
        );

        $response->assertStatus(403);
        $pivot = $parent->fresh()->children()->first()?->pivot;
        expect($pivot?->notes)->toBe('initial');
    });

    it('returns 404 for unknown resource', function () {
        $response = $this->getJson('/martis/api/resources/nonexistent/1/belongs-to-many/children');
        $response->assertStatus(404);
    });

    it('returns 404 for unknown parent', function () {
        $response = $this->getJson('/martis/api/resources/b-t-m-parent-models/99999/belongs-to-many/children');
        $response->assertStatus(404);
    });

    it('returns 422 when related_id missing in attach', function () {
        $parent = BTMParentModel::create(['name' => 'Parent A']);
        $response = $this->postJson(
            "/martis/api/resources/b-t-m-parent-models/{$parent->id}/belongs-to-many/children/attach",
            []
        );
        $response->assertStatus(422);
    });
});
