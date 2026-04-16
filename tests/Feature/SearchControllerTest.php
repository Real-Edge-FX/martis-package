<?php

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

    public static function globallySearchable(): bool
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

// ---------------------------------------------------------------------------
// Setup / teardown
// ---------------------------------------------------------------------------

beforeEach(function () {
    config([
        'database.default' => 'mysql',
        'database.connections.mysql' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'martis_playground',
            'username' => 'martis',
            'password' => 'martis_password',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
        ],
    ]);

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
