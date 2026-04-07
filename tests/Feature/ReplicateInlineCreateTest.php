<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsTo;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class ReplicateTestModel extends Model
{
    protected $table = 'martis_test_replicate';

    protected $fillable = ['title', 'body', 'category_id'];
}

class InlineCreateCategoryModel extends Model
{
    protected $table = 'martis_test_inline_categories';

    protected $fillable = ['name', 'description'];
}

class ReplicateTestResource extends Resource
{
    public static function model(): string
    {
        return ReplicateTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->sortable()->searchable()->required(),
            Textarea::make('body')->nullable(),
            BelongsTo::make('category_id', 'Category')
                ->relatedResource('inline-create-category-models')
                ->showCreateRelationButton()
                ->modalSize('lg'),
        ];
    }
}

class InlineCreateCategoryResource extends Resource
{
    public static function model(): string
    {
        return InlineCreateCategoryModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->required()->searchable(),
            Textarea::make('description')->nullable(),
        ];
    }

    public function fieldsForInlineCreate(Request $request): array
    {
        return [
            Text::make('name')->required(),
        ];
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }
}

// ---------------------------------------------------------------------------
// Database + registry setup
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

    Schema::dropIfExists('martis_test_replicate');
    Schema::dropIfExists('martis_test_inline_categories');

    Schema::create('martis_test_inline_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->text('description')->nullable();
        $table->timestamps();
    });

    Schema::create('martis_test_replicate', function ($table) {
        $table->id();
        $table->string('title');
        $table->text('body')->nullable();
        $table->unsignedBigInteger('category_id')->nullable();
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(ReplicateTestResource::class);
    $registry->register(InlineCreateCategoryResource::class);
});

afterEach(function () {
    Schema::dropIfExists('martis_test_replicate');
    Schema::dropIfExists('martis_test_inline_categories');
});

// ---------------------------------------------------------------------------
// Replicate Fields Endpoint
// URI key: ReplicateTestModel -> replicate-test-models
// ---------------------------------------------------------------------------

it('replicate fields endpoint returns pre-filled values', function () {
    $post = ReplicateTestModel::create([
        'title' => 'Original Post',
        'body' => 'Some body content',
        'category_id' => null,
    ]);

    $response = $this->getJson("/martis/api/resources/replicate-test-models/{$post->id}/replicate");

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveKey('values')
        ->and($data)->toHaveKey('fromResourceId', $post->id)
        ->and($data['values']['title'])->toBe('Original Post')
        ->and($data['values']['body'])->toBe('Some body content');
});

it('replicate fields returns 404 for non-existent record', function () {
    $response = $this->getJson('/martis/api/resources/replicate-test-models/99999/replicate');

    $response->assertNotFound();
});

// ---------------------------------------------------------------------------
// Inline Create Schema Endpoint
// URI key: InlineCreateCategoryModel -> inline-create-category-models
// ---------------------------------------------------------------------------

it('inline create schema returns fieldsForInlineCreate fields', function () {
    $response = $this->getJson('/martis/api/resources/inline-create-category-models/inline-create-schema');

    $response->assertOk();
    $fields = $response->json('data.fields');

    // fieldsForInlineCreate only defines 'name', not 'description'
    $attributes = array_column($fields, 'attribute');
    expect($attributes)->toContain('name')
        ->and($attributes)->not->toContain('description');
});

it('inline create schema falls back to fieldsForCreate when no override', function () {
    // ReplicateTestResource has no fieldsForInlineCreate, should fall back
    $response = $this->getJson('/martis/api/resources/replicate-test-models/inline-create-schema');

    $response->assertOk();
    $fields = $response->json('data.fields');

    $attributes = array_column($fields, 'attribute');
    expect($attributes)->toContain('title')
        ->and($attributes)->toContain('body');
});

// ---------------------------------------------------------------------------
// Inline Create Store Endpoint
// ---------------------------------------------------------------------------

it('inline create store creates a record and returns id + title', function () {
    $response = $this->postJson('/martis/api/resources/inline-create-category-models/inline-create', [
        'name' => 'New Test Category',
    ]);

    $response->assertCreated();
    $data = $response->json('data');

    expect($data)->toHaveKey('id')
        ->and($data)->toHaveKey('title', 'New Test Category');

    $this->assertDatabaseHas('martis_test_inline_categories', ['name' => 'New Test Category']);
});

it('inline create store validates required fields', function () {
    $response = $this->postJson('/martis/api/resources/inline-create-category-models/inline-create', []);

    $response->assertStatus(422);
});

it('inline create store blocks nested depth', function () {
    $response = $this->postJson(
        '/martis/api/resources/inline-create-category-models/inline-create',
        ['name' => 'Nested Category'],
        ['X-Martis-Inline-Create-Depth' => '1']
    );

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('depth');
});
