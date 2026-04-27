<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Martis\Cards\Card;
use Martis\Dashboards\Dashboard;
use Martis\Enums\FilterType;
use Martis\Fields\Text;
use Martis\Filters\Filter as ResourceFilter;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Requests\LensRequest;
use Martis\Layout\Panel;
use Martis\Layout\Section;
use Martis\Lenses\Lens;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Test fixtures — test models + Martis resources
// ---------------------------------------------------------------------------

class PostModel extends Model
{
    protected $table = 'martis_test_posts';

    protected $fillable = ['title', 'body'];
}

class RecentlyUpdatedLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query->orderByDesc('updated_at');
    }
}

class PublishedStateFilter extends ResourceFilter
{
    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('status', $value);
    }

    public function filterType(): FilterType
    {
        return FilterType::Select;
    }
}

class SoftPostModel extends Model
{
    use SoftDeletes;

    protected $table = 'martis_test_soft_posts';

    protected $fillable = ['title'];
}

class PostResource extends Resource
{
    public static function model(): string
    {
        return PostModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->sortable()->searchable()->required(),
            Text::make('body')->nullable(),
        ];
    }
}

class SoftPostResource extends Resource
{
    public static function model(): string
    {
        return SoftPostModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->required(),
        ];
    }
}
class VisibilityTestModel extends Model
{
    protected $table = 'martis_test_posts';

    protected $fillable = ['title', 'body'];
}

class VisibilityTestResource extends Resource
{
    public static function model(): string
    {
        return VisibilityTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('plain'),
            Text::make('index_only')->onlyOnIndex(),
            Text::make('detail_only')->onlyOnDetail(),
            Text::make('form_only')->onlyOnForms(),
            Text::make('no_forms')->exceptOnForms(),
            Text::make('hidden_on_create')->hideWhenCreating(),
            Text::make('hidden_on_update')->hideWhenUpdating(),
        ];
    }
}

class SchemaFoundationResource extends Resource
{
    public static function model(): string
    {
        return PostModel::class;
    }

    public static function uriKey(): string
    {
        return 'schema-foundation-resources';
    }

    public static function label(): string
    {
        return 'Schema Foundations';
    }

    public static function singularLabel(): string
    {
        return 'Schema Foundation';
    }

    public function group(): ?string
    {
        return 'Playground QA';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->required(),
        ];
    }

    public function filters(Request $request): array
    {
        return [
            PublishedStateFilter::make('Published State')->withMeta([
                'options' => ['draft', 'published'],
            ]),
        ];
    }

    public function lenses(Request $request): array
    {
        return [
            (new RecentlyUpdatedLens)->withMeta([
                'description' => 'Foundation-only lens descriptor for schema testing.',
            ]),
        ];
    }

    public function cards(Request $request): array
    {
        return [
            Card::make('Posts Overview')->componentKey('posts-overview-card'),
        ];
    }

    public static function dashboards(): array
    {
        return [
            Dashboard::make('Operations Dashboard')->componentKey('operations-dashboard'),
        ];
    }
}

// ---------------------------------------------------------------------------
// Database + registry setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    // Disable auth middleware for API tests
    $this->withoutMiddleware(MartisAuthenticate::class);

    // Create temporary test tables
    Schema::dropIfExists('martis_test_soft_posts');
    Schema::dropIfExists('martis_test_posts');

    Schema::create('martis_test_posts', function ($table) {
        $table->id();
        $table->string('title');
        $table->text('body')->nullable();
        $table->timestamps();
    });

    Schema::create('martis_test_soft_posts', function ($table) {
        $table->id();
        $table->string('title');
        $table->softDeletes();
        $table->timestamps();
    });

    // Register test resources
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(PostResource::class);
    $registry->register(SoftPostResource::class);
    $registry->register(VisibilityTestResource::class);
    $registry->register(SchemaFoundationResource::class);
});

afterEach(function () {
    Schema::dropIfExists('martis_test_soft_posts');
    Schema::dropIfExists('martis_test_posts');
});

// ---------------------------------------------------------------------------
// Index
// ---------------------------------------------------------------------------

it('GET /martis/api/resources/{resource} returns paginated list', function () {
    PostModel::create(['title' => 'First', 'body' => 'A']);
    PostModel::create(['title' => 'Second', 'body' => 'B']);

    $response = $this->getJson('/martis/api/resources/post-models');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data',
        'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
        'links' => ['first', 'last', 'prev', 'next'],
    ]);
    $response->assertJsonPath('meta.total', 2);
    expect(count($response->json('data')))->toBe(2);
});

it('index returns 404 for unknown resource', function () {
    $response = $this->getJson('/martis/api/resources/unknown-resource');
    $response->assertStatus(404);
});

it('index supports search query param', function () {
    PostModel::create(['title' => 'Hello World', 'body' => null]);
    PostModel::create(['title' => 'Goodbye', 'body' => null]);

    $response = $this->getJson('/martis/api/resources/post-models?search=Hello');

    $response->assertStatus(200);
    $response->assertJsonPath('meta.total', 1);
    expect($response->json('data.0.title'))->toBe('Hello World');
});

it('index supports sorting by sortable field ascending', function () {
    PostModel::create(['title' => 'Zebra', 'body' => null]);
    PostModel::create(['title' => 'Alpha', 'body' => null]);

    $response = $this->getJson('/martis/api/resources/post-models?sort=title&direction=asc');

    $response->assertStatus(200);
    expect($response->json('data.0.title'))->toBe('Alpha');
});

it('index supports sorting by sortable field descending', function () {
    PostModel::create(['title' => 'Zebra', 'body' => null]);
    PostModel::create(['title' => 'Alpha', 'body' => null]);

    $response = $this->getJson('/martis/api/resources/post-models?sort=title&direction=desc');

    $response->assertStatus(200);
    expect($response->json('data.0.title'))->toBe('Zebra');
});

it('index ignores sort on non-sortable field', function () {
    PostModel::create(['title' => 'A', 'body' => 'Z body']);
    PostModel::create(['title' => 'Z', 'body' => 'A body']);

    $response = $this->getJson('/martis/api/resources/post-models?sort=body&direction=asc');

    $response->assertStatus(200);
    // body is not sortable — default insertion order preserved
    expect($response->json('data.0.title'))->toBe('A');
});

it('index search flattens layout containers in fields() (regression)', function () {
    // Same shape as the sorting regression below — when `fields()` wraps
    // a `searchable()` field inside Panel/Section/TabGroup, the search
    // filter must still see it. Otherwise the LIKE clause is never
    // attached to the query and `?search=…` silently returns every row
    // (the bug originally reported on the Tasks index, which has a
    // `Section::make('Linkage', [Text::make('title')->searchable()])`
    // fields tree).
    $registry = app(ResourceRegistry::class);
    $registry->flush();

    $registry->register((new class extends Resource
    {
        public static function model(): string
        {
            return PostModel::class;
        }

        public static function uriKey(): string
        {
            return 'sectioned-posts';
        }

        public static function titleAttribute(): string
        {
            return 'title';
        }

        public function fields(Request $request): array
        {
            return [
                Section::make('Linkage', [
                    Text::make('title')->sortable()->searchable()->required(),
                    Text::make('body')->nullable()->searchable(),
                ]),
            ];
        }
    })::class);

    PostModel::create(['title' => 'Pipeline build', 'body' => null]);
    PostModel::create(['title' => 'Other task', 'body' => 'mentions pipeline somewhere']);
    PostModel::create(['title' => 'Unrelated', 'body' => null]);

    $response = $this->getJson('/martis/api/resources/sectioned-posts?search=pipeline');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(2);
    $titles = collect($response->json('data'))->pluck('title')->all();
    expect($titles)->toContain('Pipeline build');
    expect($titles)->toContain('Other task');
    expect($titles)->not->toContain('Unrelated');
});

it('index sorting flattens layout containers in fields() (regression)', function () {
    // Resource that wraps its fields in a Panel — applySorting() must
    // flatten the layout tree before filtering by FieldContract,
    // otherwise the Panel itself fails the closure type hint and
    // throws a TypeError. This guards against the bug surfaced when
    // a fields() definition contains any Panel / TabGroup / Section.
    $registry = app(ResourceRegistry::class);
    $registry->flush();

    $registry->register((new class extends Resource
    {
        public static function model(): string
        {
            return PostModel::class;
        }

        public static function uriKey(): string
        {
            return 'paneled-posts';
        }

        public static function titleAttribute(): string
        {
            return 'title';
        }

        public function fields(Request $request): array
        {
            return [
                Panel::make('Details', [
                    Text::make('title')->sortable()->searchable()->required(),
                    Text::make('body')->nullable(),
                ]),
            ];
        }
    })::class);

    PostModel::create(['title' => 'Z post', 'body' => null]);
    PostModel::create(['title' => 'A post', 'body' => null]);

    $response = $this->getJson('/martis/api/resources/paneled-posts?sort=title&direction=asc');

    $response->assertStatus(200);
    expect($response->json('data.0.title'))->toBe('A post');
});

it('index respects per_page param', function () {
    foreach (range(1, 10) as $i) {
        PostModel::create(['title' => "Post {$i}", 'body' => null]);
    }

    $response = $this->getJson('/martis/api/resources/post-models?per_page=3');

    $response->assertStatus(200);
    $response->assertJsonPath('meta.per_page', 3);
    $response->assertJsonPath('meta.total', 10);
    $response->assertJsonPath('meta.last_page', 4);
    expect(count($response->json('data')))->toBe(3);
});

// ---------------------------------------------------------------------------
// Show
// ---------------------------------------------------------------------------

it('GET /martis/api/resources/{resource}/{id} returns single resource', function () {
    $post = PostModel::create(['title' => 'My Post', 'body' => 'Content here']);

    $response = $this->getJson("/martis/api/resources/post-models/{$post->id}");

    $response->assertStatus(200);
    $response->assertJsonStructure(['data', 'meta', 'links']);
    expect($response->json('data.title'))->toBe('My Post');
    expect($response->json('data.body'))->toBe('Content here');
});

it('show returns 404 for missing record', function () {
    $response = $this->getJson('/martis/api/resources/post-models/999999');
    $response->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

it('POST /martis/api/resources/{resource} creates a new record', function () {
    $response = $this->postJson('/martis/api/resources/post-models', [
        'title' => 'New Post',
        'body' => 'Some body',
    ]);

    $response->assertStatus(201);
    expect($response->json('data.title'))->toBe('New Post');
    expect(PostModel::count())->toBe(1);
});

it('store returns 422 with field-level errors when validation fails', function () {
    $response = $this->postJson('/martis/api/resources/post-models', []);

    $response->assertStatus(422);
    $response->assertJsonStructure([
        'message',
        'errors' => [['field', 'message', 'code']],
    ]);

    $fields = collect($response->json('errors'))->pluck('field')->toArray();
    expect($fields)->toContain('title');
});

// ---------------------------------------------------------------------------
// Update
// ---------------------------------------------------------------------------

it('PUT /martis/api/resources/{resource}/{id} updates an existing record', function () {
    $post = PostModel::create(['title' => 'Original', 'body' => null]);

    $response = $this->putJson("/martis/api/resources/post-models/{$post->id}", [
        'title' => 'Updated',
    ]);

    $response->assertStatus(200);
    expect($response->json('data.title'))->toBe('Updated');
    expect($post->fresh()->title)->toBe('Updated');
});

it('update returns 404 for missing record', function () {
    $response = $this->putJson('/martis/api/resources/post-models/999999', ['title' => 'X']);
    $response->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Destroy
// ---------------------------------------------------------------------------

it('DELETE /martis/api/resources/{resource}/{id} deletes a record', function () {
    $post = PostModel::create(['title' => 'To Delete', 'body' => null]);

    $response = $this->deleteJson("/martis/api/resources/post-models/{$post->id}");

    $response->assertStatus(200);
    expect(PostModel::find($post->id))->toBeNull();
});

it('destroy returns 404 for missing record', function () {
    $response = $this->deleteJson('/martis/api/resources/post-models/999999');
    $response->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Soft delete + Restore
// ---------------------------------------------------------------------------

it('destroy soft-deletes the record when model uses SoftDeletes', function () {
    $post = SoftPostModel::create(['title' => 'Soft Delete Me']);

    $response = $this->deleteJson("/martis/api/resources/soft-post-models/{$post->id}");

    $response->assertStatus(200);
    expect(SoftPostModel::find($post->id))->toBeNull();
    expect(SoftPostModel::withTrashed()->find($post->id))->not->toBeNull();
});

it('PUT /martis/api/resources/{resource}/{id}/restore restores a soft-deleted record', function () {
    $post = SoftPostModel::create(['title' => 'Restore Me']);
    $post->delete();

    $response = $this->putJson("/martis/api/resources/soft-post-models/{$post->id}/restore");

    $response->assertStatus(200);
    expect(SoftPostModel::find($post->id))->not->toBeNull();
});

it('restore returns 404 for resource without soft deletes', function () {
    $response = $this->putJson('/martis/api/resources/post-models/1/restore');
    $response->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Route registration
// ---------------------------------------------------------------------------

it('registers all six resource API routes', function () {
    $routeNames = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->getName())
        ->filter()
        ->values()
        ->toArray();

    expect($routeNames)->toContain('martis.api.resources.index');
    expect($routeNames)->toContain('martis.api.resources.store');
    expect($routeNames)->toContain('martis.api.resources.show');
    expect($routeNames)->toContain('martis.api.resources.update');
    expect($routeNames)->toContain('martis.api.resources.destroy');
    expect($routeNames)->toContain('martis.api.resources.restore');
});

// ---------------------------------------------------------------------------
// Schema — GET /api/resources/{resource}/schema  (Bloco 8)
// ---------------------------------------------------------------------------

it('GET /martis/api/resources/{resource}/schema returns field schema', function () {
    $response = $this->getJson('/martis/api/resources/post-models/schema');

    $response->assertStatus(200);

    $data = $response->json('data');
    expect($data['uriKey'])->toBe('post-models');
    expect($data['label'])->toBe('Post Models');
    expect($data['singularLabel'])->toBe('Post Model');
    expect($data['softDeletes'])->toBeFalse();
    expect($data['fields'])->toBeArray();
    expect($data['fields'])->not->toBeEmpty();
});

it('schema includes field metadata with all required keys', function () {
    $response = $this->getJson('/martis/api/resources/post-models/schema');
    $response->assertStatus(200);

    $fields = $response->json('data.fields');
    $titleField = collect($fields)->firstWhere('attribute', 'title');

    expect($titleField)->not->toBeNull();
    expect($titleField['label'])->toBe('Title');
    expect($titleField['type'])->toBe('text');
    expect($titleField['sortable'])->toBeTrue();
    expect($titleField['searchable'])->toBeTrue();
    expect($titleField['required'])->toBeTrue();
    expect($titleField['showOnIndex'])->toBeTrue();
    expect($titleField['showOnDetail'])->toBeTrue();
    expect($titleField['showOnForms'])->toBeTrue();
});

it('schema returns all fields with visibility metadata', function () {
    $response = $this->getJson('/martis/api/resources/post-models/schema');
    $response->assertStatus(200);

    $fields = $response->json('data.fields');
    $bodyField = collect($fields)->firstWhere('attribute', 'body');

    // schema() returns ALL fields from fields() with their metadata
    expect($bodyField)->not->toBeNull();
    expect($bodyField)->toHaveKey('showOnIndex');
    expect($bodyField)->toHaveKey('showOnDetail');
    expect($bodyField)->toHaveKey('showOnForms');
});

it('schema returns 404 for unknown resource', function () {
    $response = $this->getJson('/martis/api/resources/unknown-resource/schema');
    $response->assertStatus(404);
});

it('schema route is registered', function () {
    $routeNames = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->getName())
        ->filter()
        ->values()
        ->toArray();

    expect($routeNames)->toContain('martis.api.resources.schema');
});

// ---------------------------------------------------------------------------
// Schema endpoint — context-aware field arrays
// ---------------------------------------------------------------------------

it('schema returns all 6 contextual field arrays pre-filtered by backend', function () {
    $response = $this->getJson('/martis/api/resources/post-models/schema');
    $response->assertStatus(200);

    $data = $response->json('data');

    // All contextual arrays must be present
    expect($data)->toHaveKey('fieldsForIndex');
    expect($data)->toHaveKey('fieldsForDetail');
    expect($data)->toHaveKey('fieldsForCreate');
    expect($data)->toHaveKey('fieldsForUpdate');
    expect($data)->toHaveKey('fieldsForInlineCreate');
    expect($data)->toHaveKey('fieldsForPreview');

    // Each must be an array
    expect($data['fieldsForIndex'])->toBeArray();
    expect($data['fieldsForDetail'])->toBeArray();
    expect($data['fieldsForCreate'])->toBeArray();
    expect($data['fieldsForUpdate'])->toBeArray();
    expect($data['fieldsForInlineCreate'])->toBeArray();
    expect($data['fieldsForPreview'])->toBeArray();
    expect($data['filters'])->toBeArray();
    expect($data['lenses'])->toBeArray();
    expect($data['cards'])->toBeArray();
    expect($data['dashboards'])->toBeArray();
});

it('schema foundation hooks default to empty arrays when not overridden', function () {
    $response = $this->getJson('/martis/api/resources/post-models/schema');
    $response->assertStatus(200);

    $data = $response->json('data');

    expect($data['filters'])->toBe([])
        ->and($data['lenses'])->toBe([])
        ->and($data['cards'])->toBe([])
        ->and($data['dashboards'])->toBe([]);
});

it('schema serializes foundation hook descriptors when overridden', function () {
    $response = $this->getJson('/martis/api/resources/schema-foundation-resources/schema');
    $response->assertStatus(200);

    $data = $response->json('data');

    expect($data['group'])->toBe('Playground QA');
    expect($data['filters'][0])->toMatchArray([
        'type' => 'filter',
        'name' => 'Published State',
        'uriKey' => 'published-state',
    ]);
    expect($data['filters'][0]['meta']['options'])->toBe(['draft', 'published']);

    expect($data['lenses'][0])->toMatchArray([
        'type' => 'lens',
        'name' => 'Recently Updated',
        'uriKey' => 'recently-updated',
    ]);

    expect($data['cards'][0])->toMatchArray([
        'type' => 'card',
        'name' => 'Posts Overview',
        'uriKey' => 'posts-overview',
        'component' => 'posts-overview-card',
    ]);

    expect($data['dashboards'][0])->toMatchArray([
        'type' => 'dashboard',
        'name' => 'Operations Dashboard',
        'uriKey' => 'operations-dashboard',
        'component' => 'operations-dashboard',
    ]);
});

it('schema contextual arrays are pre-filtered — frontend should not need to filter', function () {
    $response = $this->getJson('/martis/api/resources/visibility-test-models/schema');
    $response->assertStatus(200);

    $data = $response->json('data');

    // Helper: get attribute names from a contextual array
    $attrs = fn (string $key) => array_column($data[$key], 'attribute');

    // index_only: should appear ONLY in fieldsForIndex
    expect($attrs('fieldsForIndex'))->toContain('index_only');
    expect($attrs('fieldsForDetail'))->not->toContain('index_only');
    expect($attrs('fieldsForCreate'))->not->toContain('index_only');
    expect($attrs('fieldsForUpdate'))->not->toContain('index_only');

    // detail_only: should appear ONLY in fieldsForDetail
    expect($attrs('fieldsForDetail'))->toContain('detail_only');
    expect($attrs('fieldsForIndex'))->not->toContain('detail_only');
    expect($attrs('fieldsForCreate'))->not->toContain('detail_only');

    // form_only: should appear in create, update, inline-create; not in index, detail
    expect($attrs('fieldsForCreate'))->toContain('form_only');
    expect($attrs('fieldsForUpdate'))->toContain('form_only');
    expect($attrs('fieldsForInlineCreate'))->toContain('form_only');
    expect($attrs('fieldsForIndex'))->not->toContain('form_only');
    expect($attrs('fieldsForDetail'))->not->toContain('form_only');

    // no_forms: should appear in index, detail, preview; not in create, update
    expect($attrs('fieldsForIndex'))->toContain('no_forms');
    expect($attrs('fieldsForDetail'))->toContain('no_forms');
    expect($attrs('fieldsForPreview'))->toContain('no_forms');
    expect($attrs('fieldsForCreate'))->not->toContain('no_forms');
    expect($attrs('fieldsForUpdate'))->not->toContain('no_forms');

    // hidden_on_create: should not appear in create or inline-create
    expect($attrs('fieldsForCreate'))->not->toContain('hidden_on_create');
    expect($attrs('fieldsForInlineCreate'))->not->toContain('hidden_on_create');
    expect($attrs('fieldsForUpdate'))->toContain('hidden_on_create');

    // hidden_on_update: should not appear in update
    expect($attrs('fieldsForUpdate'))->not->toContain('hidden_on_update');
    expect($attrs('fieldsForCreate'))->toContain('hidden_on_update');

    // plain: should appear everywhere
    expect($attrs('fieldsForIndex'))->toContain('plain');
    expect($attrs('fieldsForDetail'))->toContain('plain');
    expect($attrs('fieldsForCreate'))->toContain('plain');
    expect($attrs('fieldsForUpdate'))->toContain('plain');
    expect($attrs('fieldsForInlineCreate'))->toContain('plain');
    expect($attrs('fieldsForPreview'))->toContain('plain');
});
