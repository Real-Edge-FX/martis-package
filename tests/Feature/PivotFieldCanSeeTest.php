<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsToMany;
use Martis\Fields\MorphToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// canSee() on the pivot fields of a BelongsToMany / MorphToMany (v1.38.0).
//
// A pivot field hidden from the user with canSee() was still serialised in
// the relationship's schema, listed in every attached record's `_pivot`,
// validated, and written from the request by the attach and the pivot
// update. It now behaves like a field of the record the user cannot see: it
// is left out of the schema, of the pivot values sent back and of the
// validation, and it never takes its value from the request (the attach
// stores its default(), the pivot update leaves it alone), as a readonly
// pivot field.
// ===========================================================================

class PFCParentModel extends Model
{
    protected $table = 'pfc_parents';

    protected $guarded = [];

    public $timestamps = false;

    public function tags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(PFCTagModel::class, 'pfc_parent_tag', 'parent_id', 'tag_id');
    }

    public function labels(): EloquentMorphToMany
    {
        return $this->morphToMany(PFCTagModel::class, 'taggable', 'pfc_taggables', null, 'tag_id');
    }
}

class PFCTagModel extends Model
{
    protected $table = 'pfc_tags';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * A plain note, a hidden required secret and a hidden tier with a default.
 *
 * @return list<Text>
 */
function pfcFields(): array
{
    return [
        Text::make('note'),
        Text::make('secret')->canSee(fn () => false)->rules(['required']),
        Text::make('tier')->canSee(fn () => false)->default('basic'),
    ];
}

class PFCParentResource extends Resource
{
    public static function model(): string
    {
        return PFCParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'pfc-parents';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsToMany::make('Tags', 'tags')->relatedResource('pfc-tags')->fields(fn () => pfcFields()),
            MorphToMany::make('Labels', 'labels')->relatedResource('pfc-tags')->fields(fn () => pfcFields()),
        ];
    }
}

class PFCTagResource extends Resource
{
    public static function model(): string
    {
        return PFCTagModel::class;
    }

    public static function uriKey(): string
    {
        return 'pfc-tags';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

function pfcDropTables(): void
{
    foreach (['pfc_taggables', 'pfc_parent_tag', 'pfc_tags', 'pfc_parents'] as $table) {
        Schema::dropIfExists($table);
    }
}

function pfcRelation(string $endpoint): string
{
    return $endpoint === 'belongs-to-many' ? 'tags' : 'labels';
}

function pfcTable(string $endpoint): string
{
    return $endpoint === 'belongs-to-many' ? 'pfc_parent_tag' : 'pfc_taggables';
}

/** @return array<string, mixed> */
function pfcStoredRow(string $endpoint): array
{
    return (array) DB::table(pfcTable($endpoint))->sole(['note', 'secret', 'tier']);
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    pfcDropTables();

    $columns = function ($table): void {
        foreach (['note', 'secret', 'tier'] as $column) {
            $table->string($column)->nullable();
        }
    };

    Schema::create('pfc_parents', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('pfc_tags', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('pfc_parent_tag', function ($table) use ($columns) {
        $table->unsignedBigInteger('parent_id');
        $table->unsignedBigInteger('tag_id');
        $columns($table);
    });
    Schema::create('pfc_taggables', function ($table) use ($columns) {
        $table->unsignedBigInteger('tag_id');
        $table->morphs('taggable');
        $columns($table);
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(PFCParentResource::class);
    $registry->register(PFCTagResource::class);

    $this->parent = PFCParentModel::create(['name' => 'Parent']);
    $this->tag = PFCTagModel::create(['name' => 'Tag']);
});

afterEach(function () {
    pfcDropTables();
});

$endpoints = ['belongs-to-many', 'morph-to-many'];

it('leaves a pivot field the user cannot see out of the relationship schema', function (string $endpoint) {
    $schema = $this->getJson('/martis/api/resources/pfc-parents/schema')->assertOk();

    $field = collect($schema->json('data.fieldsForDetail'))->firstWhere('attribute', pfcRelation($endpoint));

    expect(array_column($field['pivotFields'], 'attribute'))->toBe(['note']);
})->with($endpoints);

it('attaches without validating or taking the value of a pivot field the user cannot see', function (string $endpoint) {
    $relation = pfcRelation($endpoint);

    // `secret` is required but hidden: the attach form never shows it.
    $this->postJson("/martis/api/resources/pfc-parents/{$this->parent->id}/{$endpoint}/{$relation}/attach", [
        'related_id' => $this->tag->id,
        'note' => 'Hello',
        'tier' => 'forged',
    ])->assertStatus(201);

    expect(pfcStoredRow($endpoint))->toBe(['note' => 'Hello', 'secret' => null, 'tier' => 'basic']);
})->with($endpoints);

it('leaves a pivot field the user cannot see alone on pivot update and sends none back', function (string $endpoint) {
    $relation = pfcRelation($endpoint);
    $this->parent->{$relation}()->attach($this->tag->id, ['note' => 'Before', 'secret' => 's3cr3t', 'tier' => 'gold']);

    $response = $this->putJson("/martis/api/resources/pfc-parents/{$this->parent->id}/{$endpoint}/{$relation}/{$this->tag->id}/pivot", [
        'note' => 'After',
        'secret' => 'forged',
        'tier' => 'forged',
    ]);

    $response->assertOk();
    expect(pfcStoredRow($endpoint))->toBe(['note' => 'After', 'secret' => 's3cr3t', 'tier' => 'gold'])
        ->and($response->json('data.pivot'))->toBe(['note' => 'After']);
})->with($endpoints);

it('lists the attached records without the pivot values the user cannot see', function (string $endpoint) {
    $relation = pfcRelation($endpoint);
    $this->parent->{$relation}()->attach($this->tag->id, ['note' => 'Hello', 'secret' => 's3cr3t', 'tier' => 'gold']);

    $pivot = $this->getJson("/martis/api/resources/pfc-parents/{$this->parent->id}/{$endpoint}/{$relation}")
        ->assertOk()
        ->json('data.0._pivot');

    expect($pivot)->toBe(['note' => 'Hello']);
})->with($endpoints);
