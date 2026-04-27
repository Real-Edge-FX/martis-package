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
