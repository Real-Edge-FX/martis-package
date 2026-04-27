<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Contracts\FieldContract;
use Martis\Fields\BelongsTo;
use Martis\Fields\Tag;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Layout\Section;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Test fixtures — Models
// ---------------------------------------------------------------------------

class IntegrationAuthor extends Model
{
    protected $table = 'integration_authors';

    protected $fillable = ['name', 'email', 'email_verified_at', 'team_id'];

    protected $casts = ['email_verified_at' => 'datetime'];

    public function team()
    {
        return $this->belongsTo(IntegrationTeam::class, 'team_id');
    }

    public function tags()
    {
        return $this->belongsToMany(IntegrationTag::class, 'integration_author_tag', 'author_id', 'tag_id');
    }
}

class IntegrationTeam extends Model
{
    protected $table = 'integration_teams';

    protected $fillable = ['name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}

class IntegrationTag extends Model
{
    protected $table = 'integration_tags';

    protected $fillable = ['name', 'is_public'];

    protected $casts = ['is_public' => 'boolean'];
}

// ---------------------------------------------------------------------------
// Test fixtures — Resources
// ---------------------------------------------------------------------------

class IntegrationAuthorResource extends Resource
{
    public static function model(): string
    {
        return IntegrationAuthor::class;
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->searchable()->sortable(),
            Text::make('email')->searchable(),
            BelongsTo::make('team_id', 'Team')
                ->relatedResource('integration-teams')
                ->titleAttribute('name')
                ->nullable(),
            Tag::make('tags', 'Tags')
                ->relatedResource('integration-tags')
                ->titleAttribute('name')
                ->nullable(),
        ];
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->whereNotNull('email_verified_at');
    }

    public static function relatableIntegrationTags(Request $request, Builder $query, ?FieldContract $field = null): Builder
    {
        return $query->where('is_public', true);
    }
}

class IntegrationTeamResource extends Resource
{
    public static function model(): string
    {
        return IntegrationTeam::class;
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->searchable()->sortable(),
        ];
    }

    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

class IntegrationTagResource extends Resource
{
    public static function model(): string
    {
        return IntegrationTag::class;
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->searchable()->sortable(),
        ];
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    // Disable auth middleware for tests
    $this->withoutMiddleware(MartisAuthenticate::class);

    // Create tables
    Schema::create('integration_teams', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::create('integration_authors', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->foreignId('team_id')->nullable();
        $table->timestamps();
    });

    Schema::create('integration_tags', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->boolean('is_public')->default(true);
        $table->timestamps();
    });

    Schema::create('integration_author_tag', function (Blueprint $table) {
        $table->id();
        $table->foreignId('author_id');
        $table->foreignId('tag_id');
        $table->timestamps();
    });

    // Register resources
    $registry = app(ResourceRegistry::class);
    $registry->register(IntegrationAuthorResource::class);
    $registry->register(IntegrationTeamResource::class);
    $registry->register(IntegrationTagResource::class);

    // Seed data
    IntegrationTeam::create(['name' => 'Active Team', 'is_active' => true]);
    IntegrationTeam::create(['name' => 'Inactive Team', 'is_active' => false]);

    IntegrationTag::create(['name' => 'Public Tag', 'is_public' => true]);
    IntegrationTag::create(['name' => 'Private Tag', 'is_public' => false]);

    IntegrationAuthor::create([
        'name' => 'Verified Author',
        'email' => 'verified@test.com',
        'email_verified_at' => now(),
        'team_id' => 1,
    ]);
    IntegrationAuthor::create([
        'name' => 'Unverified Author',
        'email' => 'unverified@test.com',
        'email_verified_at' => null,
        'team_id' => null,
    ]);
});

afterEach(function () {
    Schema::dropIfExists('integration_author_tag');
    Schema::dropIfExists('integration_tags');
    Schema::dropIfExists('integration_authors');
    Schema::dropIfExists('integration_teams');

    app(ResourceRegistry::class)->flush();
});

// ---------------------------------------------------------------------------
// Integration tests — indexQuery
// ---------------------------------------------------------------------------

test('index endpoint applies indexQuery filter', function () {
    $response = $this->getJson('/martis/api/resources/integration-authors');

    $response->assertOk();
    $data = $response->json('data');

    // Only verified author should appear (indexQuery filters)
    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('Verified Author');
});

test('index with pagination respects indexQuery', function () {
    $response = $this->getJson('/martis/api/resources/integration-authors?per_page=10');

    $response->assertOk();
    $meta = $response->json('meta');
    expect($meta['total'])->toBe(1); // Only 1 verified
});

test('index with search works alongside indexQuery', function () {
    // Search for 'Unverified' — should return nothing because indexQuery filters unverified
    $response = $this->getJson('/martis/api/resources/integration-authors?search=Unverified');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

test('index with sorting works alongside indexQuery', function () {
    // Add another verified user
    IntegrationAuthor::create([
        'name' => 'Alpha Author',
        'email' => 'alpha@test.com',
        'email_verified_at' => now(),
    ]);

    $response = $this->getJson('/martis/api/resources/integration-authors?sort=name&direction=asc');

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(2);
    expect($data[0]['name'])->toBe('Alpha Author');
});

// ---------------------------------------------------------------------------
// Integration tests — relatable endpoint with relatableQuery
// ---------------------------------------------------------------------------

test('relatable endpoint applies target relatableQuery', function () {
    $response = $this->getJson('/martis/api/resources/integration-authors/1/relatable/team_id');

    $response->assertOk();
    $data = $response->json('data');

    // Only active teams should appear (TeamResource::relatableQuery)
    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('Active Team');
});

// ---------------------------------------------------------------------------
// Integration tests — relatable endpoint with dynamic method
// ---------------------------------------------------------------------------

test('relatable endpoint uses dynamic relatableTags method', function () {
    $response = $this->getJson('/martis/api/resources/integration-authors/1/relatable/tags');

    $response->assertOk();
    $data = $response->json('data');

    // relatableTags filters to is_public = true
    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('Public Tag');
});

// ---------------------------------------------------------------------------
// Integration tests — relatable endpoint with search
// ---------------------------------------------------------------------------

test('relatable endpoint supports search', function () {
    IntegrationTeam::create(['name' => 'Another Active', 'is_active' => true]);

    $response = $this->getJson('/martis/api/resources/integration-authors/1/relatable/team_id?search=Another');

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('Another Active');
});

test('relatable endpoint search flattens layout containers in fields() (regression)', function () {
    // Repro for the same family of bugs as the Tasks index search:
    // when the related resource wraps its searchable fields inside a
    // Section / Panel / TabGroup, the relatable endpoint silently
    // returned the unfiltered set because the top-level `instanceof
    // FieldContract` filter dropped every nested searchable. Register
    // a dedicated team resource whose `name` lives inside a Section
    // and confirm the search filter still narrows the result set.
    $registry = app(ResourceRegistry::class);

    $registry->register(new class extends Resource
    {
        public static function model(): string
        {
            return IntegrationTeam::class;
        }

        public static function uriKey(): string
        {
            return 'integration-teams-sectioned';
        }

        public static function titleAttribute(): string
        {
            return 'name';
        }

        public function fields(Request $request): array
        {
            return [
                Section::make('Linkage', [
                    Text::make('name')->searchable()->sortable(),
                ]),
            ];
        }

        public static function relatableQuery(Request $request, Builder $query): Builder
        {
            return $query;
        }
    }::class);

    // Author resource pointing at the sectioned teams via team_id.
    $registry->register(new class extends Resource
    {
        public static function model(): string
        {
            return IntegrationAuthor::class;
        }

        public static function uriKey(): string
        {
            return 'integration-authors-sectioned';
        }

        public function fields(Request $request): array
        {
            return [
                Text::make('name'),
                BelongsTo::make('team_id', 'Team')
                    ->relatedResource('integration-teams-sectioned')
                    ->titleAttribute('name'),
            ];
        }
    }::class);

    IntegrationTeam::create(['name' => 'Pipeline Squad', 'is_active' => true]);
    IntegrationTeam::create(['name' => 'Other Squad', 'is_active' => true]);

    $response = $this->getJson('/martis/api/resources/integration-authors-sectioned/_/relatable/team_id?search=Pipeline');

    $response->assertOk();
    $data = $response->json('data');
    $names = collect($data)->pluck('name')->all();
    expect($names)->toContain('Pipeline Squad');
    expect($names)->not->toContain('Other Squad');
});

// ---------------------------------------------------------------------------
// Integration tests — relatable for create context (id = "_")
// ---------------------------------------------------------------------------

test('relatable endpoint works with underscore id for create context', function () {
    $response = $this->getJson('/martis/api/resources/integration-authors/_/relatable/team_id');

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1); // Only active team
});

// ---------------------------------------------------------------------------
// Integration tests — error cases
// ---------------------------------------------------------------------------

test('relatable endpoint returns 404 for unknown field', function () {
    $response = $this->getJson('/martis/api/resources/integration-authors/1/relatable/nonexistent');

    $response->assertNotFound();
});

test('relatable endpoint returns 404 for unknown resource', function () {
    $response = $this->getJson('/martis/api/resources/nonexistent/1/relatable/field');

    $response->assertNotFound();
});

// ---------------------------------------------------------------------------
// Integration tests — BelongsTo with relatable
// ---------------------------------------------------------------------------

test('BelongsTo field resolves correctly for relatable', function () {
    $field = BelongsTo::make('team_id', 'Team')
        ->relatedResource('integration-teams')
        ->titleAttribute('name');

    $author = IntegrationAuthor::find(1);
    $resolved = $field->resolve($author);

    expect($resolved)->toBeArray();
    expect($resolved['id'])->toBe(1);
    expect($resolved['title'])->toBe('Active Team');
});

// ---------------------------------------------------------------------------
// Integration tests — schema includes query hook methods signature
// ---------------------------------------------------------------------------

test('schema endpoint still works after query hooks addition', function () {
    $response = $this->getJson('/martis/api/resources/integration-authors/schema');

    $response->assertOk();
    $data = $response->json('data');
    expect($data['uriKey'])->toBe('integration-authors');
    expect($data['fields'])->toBeArray();
});

// ---------------------------------------------------------------------------
// Integration tests — context-free relatable (no source resource)
// ---------------------------------------------------------------------------

test('relatable endpoint works without source resource using related_resource param', function () {
    $response = $this->getJson('/martis/api/resources/_/_/relatable/team_id?related_resource=integration-teams');

    $response->assertOk();
    $data = $response->json('data');

    // Should apply IntegrationTeamResource::relatableQuery (is_active = true)
    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('Active Team');
});

test('context-free relatable returns 404 for unknown related_resource', function () {
    $response = $this->getJson('/martis/api/resources/_/_/relatable/field?related_resource=nonexistent');

    $response->assertNotFound();
});

test('context-free relatable returns 404 when no related_resource param', function () {
    $response = $this->getJson('/martis/api/resources/_/_/relatable/field');

    $response->assertNotFound();
});
