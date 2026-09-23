<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasOne as EloquentHasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\HasMany;
use Martis\Fields\HasOne;
use Martis\Fields\MorphMany;
use Martis\Fields\MorphOne;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// immutable() on the relationship write endpoints.
//
// An immutable field is writable on create and silently skipped on update:
// the request is accepted, the other fields are written and the column keeps
// its stored value. The HasMany / HasOne / MorphMany / MorphOne inline forms
// honour it exactly as the resource's own endpoints do. Every test below
// also runs against the resource endpoint, the reference the relationship
// endpoints match.
//
// Only ResourceController::fillFields() used to skip immutable fields, so
// every inline update overwrote them.
// ===========================================================================

class RIFParentModel extends Model
{
    protected $table = 'rif_parents';

    protected $guarded = [];

    public $timestamps = false;

    public function children(): EloquentHasMany
    {
        return $this->hasMany(RIFChildModel::class, 'parent_id');
    }

    public function child(): EloquentHasOne
    {
        return $this->hasOne(RIFChildModel::class, 'parent_id');
    }

    public function notes(): EloquentMorphMany
    {
        return $this->morphMany(RIFNoteModel::class, 'notable');
    }

    public function note(): EloquentMorphOne
    {
        return $this->morphOne(RIFNoteModel::class, 'notable');
    }
}

class RIFChildModel extends Model
{
    protected $table = 'rif_children';

    protected $guarded = [];

    public $timestamps = false;
}

class RIFNoteModel extends Model
{
    protected $table = 'rif_notes';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * The fields every related record declares: an immutable slug next to a
 * plain title.
 *
 * @return list<Text>
 */
function rifFields(): array
{
    return [
        Text::make('slug')->immutable()->required(),
        Text::make('title')->required(),
    ];
}

class RIFParentResource extends Resource
{
    public static function model(): string
    {
        return RIFParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'rif-parents';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Children', 'children')->relatedResource('rif-children'),
            HasOne::make('Child', 'child')->relatedResource('rif-children'),
            MorphMany::make('Notes', 'notes')->relatedResource('rif-notes'),
            MorphOne::make('Note', 'note')->relatedResource('rif-notes'),
        ];
    }
}

class RIFChildResource extends Resource
{
    public static function model(): string
    {
        return RIFChildModel::class;
    }

    public static function uriKey(): string
    {
        return 'rif-children';
    }

    public function fields(Request $request): array
    {
        return rifFields();
    }
}

class RIFNoteResource extends Resource
{
    public static function model(): string
    {
        return RIFNoteModel::class;
    }

    public static function uriKey(): string
    {
        return 'rif-notes';
    }

    public function fields(Request $request): array
    {
        return rifFields();
    }
}

function rifDropTables(): void
{
    foreach (['rif_notes', 'rif_children', 'rif_parents'] as $table) {
        Schema::dropIfExists($table);
    }
}

/**
 * The relationship method behind an inline endpoint.
 */
function rifRelation(string $endpoint): string
{
    return match ($endpoint) {
        'has-many' => 'children',
        'has-one' => 'child',
        'morph-many' => 'notes',
        'morph-one' => 'note',
    };
}

/**
 * The model an endpoint writes.
 *
 * @return class-string<Model>
 */
function rifRecordModel(string $endpoint): string
{
    return in_array($endpoint, ['morph-many', 'morph-one'], true) ? RIFNoteModel::class : RIFChildModel::class;
}

/**
 * Return the create URL of an endpoint.
 */
function rifCreateUrl(string $endpoint): string
{
    if ($endpoint === 'resource') {
        return '/martis/api/resources/rif-children';
    }

    $parent = RIFParentModel::create(['name' => 'Parent']);

    return "/martis/api/resources/rif-parents/{$parent->id}/{$endpoint}/".rifRelation($endpoint);
}

/**
 * Create the record an update endpoint edits, and return the update URL
 * with the record.
 *
 * @param  array<string, mixed>  $values
 * @return array{0: string, 1: Model}
 */
function rifUpdateTarget(string $endpoint, array $values): array
{
    if ($endpoint === 'resource') {
        $record = RIFChildModel::create($values);

        return ["/martis/api/resources/rif-children/{$record->id}", $record];
    }

    $parent = RIFParentModel::create(['name' => 'Parent']);
    $relation = rifRelation($endpoint);
    $record = $parent->{$relation}()->create($values);

    // The to-many update URL names the related record; the to-one URL does not.
    $url = "/martis/api/resources/rif-parents/{$parent->id}/{$endpoint}/{$relation}";

    return [in_array($endpoint, ['has-many', 'morph-many'], true) ? "{$url}/{$record->id}" : $url, $record];
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    rifDropTables();

    Schema::create('rif_parents', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('rif_children', function ($table) {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->string('slug');
        $table->string('title');
    });
    Schema::create('rif_notes', function ($table) {
        $table->id();
        $table->nullableMorphs('notable');
        $table->string('slug');
        $table->string('title');
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(RIFParentResource::class);
    $registry->register(RIFChildResource::class);
    $registry->register(RIFNoteResource::class);
});

afterEach(function () {
    rifDropTables();
});

$endpoints = ['resource', 'has-many', 'has-one', 'morph-many', 'morph-one'];

it('stores an immutable field on create', function (string $endpoint) {
    $this->postJson(rifCreateUrl($endpoint), ['slug' => 'original-slug', 'title' => 'First'])
        ->assertStatus(201);

    expect(rifRecordModel($endpoint)::query()->sole()->only(['slug', 'title']))
        ->toBe(['slug' => 'original-slug', 'title' => 'First']);
})->with($endpoints);

it('skips an immutable field on update and still writes the other fields', function (string $endpoint) {
    [$url, $record] = rifUpdateTarget($endpoint, ['slug' => 'frozen', 'title' => 'Before']);

    $this->putJson($url, ['slug' => 'tampered', 'title' => 'After'])
        ->assertStatus(200);

    expect($record->fresh()->only(['slug', 'title']))
        ->toBe(['slug' => 'frozen', 'title' => 'After']);
})->with($endpoints);
