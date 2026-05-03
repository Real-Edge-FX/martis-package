<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class SearchTestPost extends Model
{
    protected $table = 'martis_search_test_posts';

    protected $fillable = ['title', 'body'];
}

// Used by the searchableRelations() test below — kept top-level so the
// `belongsTo` relation can resolve via class-string.
class SearchRelationAuthor extends Model
{
    protected $table = 'martis_search_relation_authors';

    protected $fillable = ['name'];

    public $timestamps = false;
}

class SearchRelationPost extends Model
{
    protected $table = 'martis_search_relation_posts';

    protected $fillable = ['title', 'author_id'];

    public function author()
    {
        return $this->belongsTo(SearchRelationAuthor::class, 'author_id');
    }
}

class SearchRelationPostResource extends Resource
{
    public static function model(): string
    {
        return SearchRelationPost::class;
    }

    public static function uriKey(): string
    {
        return 'search-relation-posts';
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')->searchable()];
    }

    public static function searchableRelations(): array
    {
        return ['author.name'];
    }
}

class SearchTestUser extends Model
{
    protected $table = 'martis_search_test_users';

    protected $fillable = ['name', 'email'];
}

class SearchTestPostResource extends Resource
{
    public static function model(): string
    {
        return SearchTestPost::class;
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->searchable()->required(),
            Text::make('body')->nullable(),
        ];
    }

    public function searchSubtitle(Model $model): ?string
    {
        $body = (string) $model->getAttribute('body');

        return $body !== '' ? $body : null;
    }
}

class SearchTestUserResource extends Resource
{
    public static function model(): string
    {
        return SearchTestUser::class;
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->searchable()->required(),
            Text::make('email')->searchable()->required(),
        ];
    }

    public function searchSubtitle(Model $model): ?string
    {
        return (string) $model->getAttribute('email');
    }
}

class SearchTestHiddenResource extends Resource
{
    public static function model(): string
    {
        return SearchTestPost::class;
    }

    public static function uriKey(): string
    {
        return 'search-test-hidden';
    }

    public static function globallySearchable(): bool|array
    {
        return false;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->searchable(),
        ];
    }
}

class SearchPostResourceWithLimit3 extends Resource
{
    public static function model(): string
    {
        return SearchTestPost::class;
    }

    public static function uriKey(): string
    {
        return 'search-test-posts-with-limit';
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    public static function globallySearchable(): bool|array
    {
        return ['limit' => 3];
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->searchable()->required(),
        ];
    }
}

class SearchPostResourceWithMinQuery1 extends Resource
{
    public static function model(): string
    {
        return SearchTestPost::class;
    }

    public static function uriKey(): string
    {
        return 'search-test-posts-tag';
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    public static function globallySearchable(): bool|array
    {
        return ['min_query' => 1];
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->searchable()->required(),
        ];
    }
}

class SearchPostResourceWithCustomOrder extends Resource
{
    public static function model(): string
    {
        return SearchTestPost::class;
    }

    public static function uriKey(): string
    {
        return 'search-test-posts-ranked';
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->searchable()->required(),
        ];
    }

    public function searchOrderBy(Builder $query, string $term): Builder
    {
        $like = addcslashes($term, '%_').'%';

        // Prefix matches first, then everything else, then alphabetical.
        return $query
            ->orderByRaw('CASE WHEN title LIKE ? THEN 0 ELSE 1 END', [$like])
            ->orderBy('title');
    }
}

// ---------------------------------------------------------------------------
// Setup / teardown
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('martis_search_test_posts');
    Schema::dropIfExists('martis_search_test_users');

    Schema::create('martis_search_test_posts', function ($table) {
        $table->id();
        $table->string('title');
        $table->text('body')->nullable();
        $table->timestamps();
    });

    Schema::create('martis_search_test_users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(SearchTestPostResource::class);
    $registry->register(SearchTestUserResource::class);
    $registry->register(SearchTestHiddenResource::class);
});

afterEach(function () {
    Schema::dropIfExists('martis_search_test_posts');
    Schema::dropIfExists('martis_search_test_users');
});

// ---------------------------------------------------------------------------
// GET /martis/api/search
// ---------------------------------------------------------------------------

it('returns empty results when query is too short', function () {
    $response = $this->getJson('/martis/api/search?q=a');

    $response->assertStatus(200)
        ->assertJson(['results' => []]);
});

it('returns empty results when query is blank', function () {
    $response = $this->getJson('/martis/api/search');

    $response->assertStatus(200)
        ->assertJson(['results' => []]);
});

it('returns grouped results across multiple resources', function () {
    SearchTestPost::create(['title' => 'Hello World', 'body' => 'Some content']);
    SearchTestUser::create(['name' => 'Hello User', 'email' => 'hello@example.com']);

    $response = $this->getJson('/martis/api/search?q=Hello');

    $response->assertStatus(200);
    $data = $response->json();

    expect($data['results'])->toBeArray()->toHaveCount(2);

    $resourceKeys = collect($data['results'])->pluck('resource')->toArray();
    expect($resourceKeys)->toContain('search-test-posts');
    expect($resourceKeys)->toContain('search-test-users');
});

it('includes title and subtitle in search results', function () {
    SearchTestPost::create(['title' => 'Searchable Post', 'body' => 'The subtitle text']);
    SearchTestUser::create(['name' => 'Search User', 'email' => 'search@example.com']);

    $response = $this->getJson('/martis/api/search?q=search');

    $response->assertStatus(200);
    $data = $response->json();

    $postGroup = collect($data['results'])->firstWhere('resource', 'search-test-posts');
    expect($postGroup)->not->toBeNull();
    expect($postGroup['items'][0]['title'])->toContain('Searchable Post');
    expect($postGroup['items'][0]['subtitle'])->toBe('The subtitle text');
    expect($postGroup['items'][0]['url'])->toContain('/resources/search-test-posts/');

    $userGroup = collect($data['results'])->firstWhere('resource', 'search-test-users');
    expect($userGroup)->not->toBeNull();
    expect($userGroup['items'][0]['subtitle'])->toBe('search@example.com');
});

it('respects globallySearchable() = false', function () {
    SearchTestPost::create(['title' => 'Hidden Resource Post', 'body' => 'test']);

    $response = $this->getJson('/martis/api/search?q=Hidden');

    $response->assertStatus(200);
    $data = $response->json();

    $resourceKeys = collect($data['results'])->pluck('resource')->toArray();
    expect($resourceKeys)->not->toContain('search-test-hidden');
});

it('returns only matching records, not all records', function () {
    SearchTestPost::create(['title' => 'Matching Alpha', 'body' => 'content']);
    SearchTestPost::create(['title' => 'Matching Beta', 'body' => 'content']);
    SearchTestPost::create(['title' => 'Unrelated Record', 'body' => 'nothing']);

    $response = $this->getJson('/martis/api/search?q=Matching');

    $response->assertStatus(200);
    $data = $response->json();

    $postGroup = collect($data['results'])->firstWhere('resource', 'search-test-posts');
    expect($postGroup['items'])->toHaveCount(2);
});

it('response has correct structure', function () {
    SearchTestPost::create(['title' => 'Structure Test', 'body' => null]);

    $response = $this->getJson('/martis/api/search?q=Structure');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'results' => [
                '*' => [
                    'resource',
                    'label',
                    'items' => [
                        '*' => ['id', 'title', 'subtitle', 'url'],
                    ],
                ],
            ],
        ]);
});

it('subtitle is null when searchSubtitle returns null', function () {
    SearchTestPost::create(['title' => 'No Body Post', 'body' => null]);

    $response = $this->getJson('/martis/api/search?q=Body');

    $response->assertStatus(200);
    $data = $response->json();

    $postGroup = collect($data['results'])->firstWhere('resource', 'search-test-posts');
    expect($postGroup['items'][0]['subtitle'])->toBeNull();
});

// ---------------------------------------------------------------------------
// ⭐ Differential 1 — per-resource config + global config defaults
// ---------------------------------------------------------------------------

it('honours martis.search.default_limit globally when no per-resource override', function () {
    config()->set('martis.search.default_limit', 2);

    foreach (range(1, 5) as $i) {
        SearchTestPost::create(['title' => "Limit Test {$i}", 'body' => null]);
    }

    $data = $this->getJson('/martis/api/search?q=Limit')->json();
    $postGroup = collect($data['results'])->firstWhere('resource', 'search-test-posts');

    expect($postGroup['items'])->toHaveCount(2);
});

it('honours martis.search.min_query globally', function () {
    config()->set('martis.search.min_query', 3);

    SearchTestPost::create(['title' => 'AB', 'body' => null]);

    // 2-char query is now under the global threshold.
    expect($this->getJson('/martis/api/search?q=AB')->json('results'))->toBe([]);

    // 3-char query passes — but still no match for "AB" since "ABC" doesn't exist.
    expect($this->getJson('/martis/api/search?q=ABC')->json('results'))->toBe([]);
});

it('per-resource limit override wins over global default', function () {
    config()->set('martis.search.default_limit', 5);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(SearchTestUserResource::class);
    $registry->register(SearchPostResourceWithLimit3::class);

    foreach (range(1, 10) as $i) {
        SearchTestPost::create(['title' => "Cap Test {$i}", 'body' => null]);
    }

    $data = $this->getJson('/martis/api/search?q=Cap')->json();
    $postGroup = collect($data['results'])->firstWhere('resource', 'search-test-posts-with-limit');

    expect($postGroup['items'])->toHaveCount(3);
});

it('per-resource min_query override wins over global default', function () {
    config()->set('martis.search.min_query', 3);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(SearchPostResourceWithMinQuery1::class);

    SearchTestPost::create(['title' => 'A-tag', 'body' => null]);

    // Single character query — disallowed globally, allowed by this resource.
    $data = $this->getJson('/martis/api/search?q=A')->json();

    expect($data['results'])->not->toBeEmpty();
    expect($data['results'][0]['items'][0]['title'])->toBe('A-tag');
});

it('legacy bool true still works as a per-resource opt-in', function () {
    SearchTestPost::create(['title' => 'Legacy Bool', 'body' => null]);

    $data = $this->getJson('/martis/api/search?q=Legacy')->json();
    $group = collect($data['results'])->firstWhere('resource', 'search-test-posts');

    expect($group)->not->toBeNull();
    expect($group['items'])->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// ⭐ Differential 2 — total + viewAllUrl
// ---------------------------------------------------------------------------

it('viewAllUrl is always present and includes the query parameter', function () {
    SearchTestPost::create(['title' => 'View All Test', 'body' => null]);

    $data = $this->getJson('/martis/api/search?q=View%20All')->json();
    $group = collect($data['results'])->firstWhere('resource', 'search-test-posts');

    expect($group['viewAllUrl'])->toBe('/resources/search-test-posts?search=View%20All');
});

it('total is omitted when items count is below the resource limit', function () {
    config()->set('martis.search.default_limit', 5);

    SearchTestPost::create(['title' => 'Single Match', 'body' => null]);

    $data = $this->getJson('/martis/api/search?q=Single')->json();
    $group = collect($data['results'])->firstWhere('resource', 'search-test-posts');

    expect($group)->not->toHaveKey('total');
});

it('total reflects the real overflow count when items hit the limit', function () {
    config()->set('martis.search.default_limit', 3);

    foreach (range(1, 10) as $i) {
        SearchTestPost::create(['title' => "Total Test {$i}", 'body' => null]);
    }

    $data = $this->getJson('/martis/api/search?q=Total%20Test')->json();
    $group = collect($data['results'])->firstWhere('resource', 'search-test-posts');

    expect($group['items'])->toHaveCount(3);
    expect($group['total'])->toBe(10);
});

// ---------------------------------------------------------------------------
// ⭐ Differential 3 — searchOrderBy() hook
// ---------------------------------------------------------------------------

it('calls searchOrderBy() hook so resources can boost prefix matches', function () {
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(SearchPostResourceWithCustomOrder::class);

    SearchTestPost::create(['title' => 'Other matches Cla in middle', 'body' => null]);
    SearchTestPost::create(['title' => 'Claudia gets boosted', 'body' => null]);
    SearchTestPost::create(['title' => 'Closer to the start with Cla', 'body' => null]);

    $data = $this->getJson('/martis/api/search?q=Cla')->json();
    $items = $data['results'][0]['items'];

    // Hook orders title-prefix matches first.
    expect($items[0]['title'])->toBe('Claudia gets boosted');
    expect($items[1]['title'])->toBe('Closer to the start with Cla');
});

// ---------------------------------------------------------------------------
// Result transformer + image hook (v1.8.x)
// ---------------------------------------------------------------------------

it('emits the image key from Resource::searchImage()', function () {
    $resourceClass = new class(null) extends SearchTestUserResource
    {
        public static function uriKey(): string
        {
            return 'search-test-users-with-image';
        }

        public function searchImage(Model $model): ?string
        {
            return 'https://gravatar.example/'.md5((string) $model->email);
        }
    };

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register($resourceClass::class);

    SearchTestUser::create(['name' => 'Avatar User', 'email' => 'av@example.com']);

    $data = $this->getJson('/martis/api/search?q=Avatar')->json();
    $items = $data['results'][0]['items'];

    expect($items[0])->toHaveKey('image');
    expect($items[0]['image'])->toStartWith('https://gravatar.example/');
});

it('Resource::globalSearchResult can attach arbitrary fields to a result', function () {
    $resourceClass = new class(null) extends SearchTestUserResource
    {
        public static function uriKey(): string
        {
            return 'search-test-users-with-shape';
        }

        public function globalSearchResult(Model $model): array
        {
            return [
                'id' => $model->getKey(),
                'title' => 'CUSTOM '.$model->name,
                'subtitle' => null,
                'image' => null,
                'url' => '/x/'.$model->getKey(),
                'badge' => 'pro',
            ];
        }
    };

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register($resourceClass::class);

    SearchTestUser::create(['name' => 'Shape', 'email' => 's@example.com']);

    $items = $this->getJson('/martis/api/search?q=Shape')->json('results.0.items');

    expect($items[0]['title'])->toBe('CUSTOM Shape');
    expect($items[0]['url'])->toBe('/x/'.SearchTestUser::first()->id);
    expect($items[0]['badge'])->toBe('pro');
});

// ---------------------------------------------------------------------------
// Per-field search priority (v1.8.x)
// ---------------------------------------------------------------------------

it('ranks results by Field::searchPriority on MySQL drivers', function () {
    if (\DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('searchPriority ORDER BY only applies on MySQL.');
    }

    $resourceClass = new class(null) extends SearchTestUserResource
    {
        public static function uriKey(): string
        {
            return 'search-test-users-priority';
        }

        public function fields(Request $request): array
        {
            return [
                Text::make('name')->searchable()->searchPriority(2),
                Text::make('email')->searchable()->searchPriority(1),
            ];
        }
    };

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register($resourceClass::class);

    SearchTestUser::create(['name' => 'Bob', 'email' => 'priority@example.com']);
    SearchTestUser::create(['name' => 'priority Anna', 'email' => 'a@example.com']);

    $items = $this->getJson('/martis/api/search?q=priority')->json('results.0.items');

    // Hit on `name` (priority 2) ranks above hit on `email` (priority 1).
    expect($items[0]['title'])->toBe('priority Anna');
    expect($items[1]['title'])->toBe('Bob');
});

// ---------------------------------------------------------------------------
// field:value query syntax (v1.8.x)
// ---------------------------------------------------------------------------

it('parses field:value tokens out of the search query', function () {
    SearchTestUser::create(['name' => 'Alpha', 'email' => 'a@example.com']);
    SearchTestUser::create(['name' => 'Beta', 'email' => 'b@example.com']);

    $items = $this->getJson('/martis/api/search?q=email:b@example.com')->json('results.0.items');

    expect($items)->toHaveCount(1);
    expect($items[0]['title'])->toBe('Beta');
});

it('combines field:value tokens with free-text search', function () {
    SearchTestUser::create(['name' => 'TopHit', 'email' => 't@example.com']);
    SearchTestUser::create(['name' => 'TopHit Two', 'email' => 't2@example.com']);
    SearchTestUser::create(['name' => 'Other', 'email' => 't@example.com']);

    $items = $this->getJson('/martis/api/search?q=TopHit email:t@example.com')->json('results.0.items');
    $titles = collect($items)->pluck('title')->all();

    expect($titles)->toContain('TopHit');
    expect($titles)->not->toContain('TopHit Two'); // wrong email
    expect($titles)->not->toContain('Other');      // wrong name
});

it('silently ignores field:value tokens whose field is not searchable', function () {
    SearchTestUser::create(['name' => 'Visible', 'email' => 'v@example.com']);

    // `created_at` is not on the searchable list — token is ignored,
    // and the resource simply does not match without free-text fallback.
    $data = $this->getJson('/martis/api/search?q=created_at:foo')->json();

    expect($data['results'])->toBe([]);
});

// ---------------------------------------------------------------------------
// Searchable detail relations (v1.8.x)
// ---------------------------------------------------------------------------

it('returns no results for a resource with no searchable fields and no relations', function () {
    // A resource that opted into globallySearchable() but forgot to
    // mark any field `searchable()` (and declared no
    // searchableRelations()) has nothing to filter against. Without a
    // guard the controller's `limit()` would dump a random first-page
    // slice — the bug that surfaced unrelated Notes for `martis.local`
    // in the playground demo.
    $resourceClass = new class(null) extends Resource
    {
        public static function model(): string
        {
            return SearchTestPost::class;
        }

        public static function uriKey(): string
        {
            return 'search-test-posts-empty-fields';
        }

        public static function titleAttribute(): string
        {
            return 'title';
        }

        public function fields(Request $request): array
        {
            // No `->searchable()` on any field.
            return [Text::make('title')];
        }
    };

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register($resourceClass::class);

    SearchTestPost::create(['title' => 'Anything', 'body' => null]);
    SearchTestPost::create(['title' => 'Whatever', 'body' => null]);

    $data = $this->getJson('/martis/api/search?q=anything')->json();

    expect($data['results'])->toBe([]);
});

it('searches across relation paths declared in searchableRelations()', function () {
    // Top-level model + resource declared in this test file (Eloquent
    // resolves relations via class names, so anonymous classes don't
    // play well with `belongsTo`).
    Schema::dropIfExists('martis_search_relation_authors');
    Schema::dropIfExists('martis_search_relation_posts');
    Schema::create('martis_search_relation_authors', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('martis_search_relation_posts', function ($table) {
        $table->id();
        $table->unsignedBigInteger('author_id')->nullable();
        $table->string('title');
        $table->timestamps();
    });

    $hero = SearchRelationAuthor::create(['name' => 'Hidden Hero']);
    SearchRelationPost::create(['title' => 'Plain post', 'author_id' => $hero->id]);
    SearchRelationPost::create(['title' => 'Solo post', 'author_id' => null]);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(SearchRelationPostResource::class);

    $items = $this->getJson('/martis/api/search?q=Hero')->json('results.0.items');

    expect($items)->toHaveCount(1);
    expect($items[0]['title'])->toBe('Plain post');

    Schema::dropIfExists('martis_search_relation_authors');
    Schema::dropIfExists('martis_search_relation_posts');
});
