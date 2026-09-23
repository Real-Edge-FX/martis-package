<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsToMany;
use Martis\Fields\Boolean;
use Martis\Fields\Field;
use Martis\Fields\MorphToMany;
use Martis\Fields\MultiSelect;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// Pivot fields are written through their own fill() (v1.38.0).
//
// The attach and the pivot update used to copy each pivot value from the
// request into the pivot row as it came, so a pivot field kept none of the
// write behaviour it has on a record: a fillUsing() callback never ran, a
// computed field was written to a column that does not exist, a MultiSelect
// sent its array to the column, and a Boolean stored the raw request value.
// They now fill a pivot model of the relationship's own class through each
// field, as Nova fills the pivot, and write what the fields set.
// ===========================================================================

class PFFCastPivot extends Pivot
{
    protected $casts = ['flags' => 'array'];
}

class PFFParentModel extends Model
{
    protected $table = 'pff_parents';

    protected $guarded = [];

    public $timestamps = false;

    public function tags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(PFFTagModel::class, 'pff_parent_tag', 'parent_id', 'tag_id');
    }

    public function castTags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(PFFTagModel::class, 'pff_parent_tag', 'parent_id', 'tag_id')
            ->using(PFFCastPivot::class)
            ->withPivot(['flags']);
    }

    public function labels(): EloquentMorphToMany
    {
        return $this->morphToMany(PFFTagModel::class, 'taggable', 'pff_taggables', null, 'tag_id');
    }
}

class PFFTagModel extends Model
{
    protected $table = 'pff_tags';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * @return list<Field>
 */
function pffFields(): array
{
    return [
        Text::make('note')->fillUsing(function (Model $model, mixed $value, string $attribute): void {
            $model->{$attribute} = strtoupper((string) $value);
        }),
        MultiSelect::make('flags')->options(['a' => 'A', 'b' => 'B', 'c' => 'C']),
        Text::make('summary')->computed(fn (): string => 'derived'),
        Boolean::make('featured'),
    ];
}

class PFFParentResource extends Resource
{
    public static function model(): string
    {
        return PFFParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'pff-parents';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsToMany::make('Tags', 'tags')->relatedResource('pff-tags')->fields(fn () => pffFields()),
            BelongsToMany::make('Cast tags', 'castTags')->relatedResource('pff-tags')->fields(fn () => [
                MultiSelect::make('flags')->options(['a' => 'A', 'b' => 'B', 'c' => 'C']),
            ]),
            MorphToMany::make('Labels', 'labels')->relatedResource('pff-tags')->fields(fn () => pffFields()),
        ];
    }
}

class PFFTagResource extends Resource
{
    public static function model(): string
    {
        return PFFTagModel::class;
    }

    public static function uriKey(): string
    {
        return 'pff-tags';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

function pffTable(string $endpoint): string
{
    return $endpoint === 'belongs-to-many' ? 'pff_parent_tag' : 'pff_taggables';
}

function pffRelation(string $endpoint): string
{
    return $endpoint === 'belongs-to-many' ? 'tags' : 'labels';
}

/**
 * @return array<string, mixed>
 */
function pffStoredRow(string $endpoint): array
{
    return (array) DB::table(pffTable($endpoint))->sole(['note', 'flags', 'featured']);
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['pff_taggables', 'pff_parent_tag', 'pff_tags', 'pff_parents'] as $table) {
        Schema::dropIfExists($table);
    }

    $pivotColumns = function ($table): void {
        $table->string('note')->nullable();
        $table->text('flags')->nullable();
        $table->boolean('featured')->default(false);
    };

    Schema::create('pff_parents', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('pff_tags', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('pff_parent_tag', function ($table) use ($pivotColumns) {
        $table->unsignedBigInteger('parent_id');
        $table->unsignedBigInteger('tag_id');
        $pivotColumns($table);
    });
    Schema::create('pff_taggables', function ($table) use ($pivotColumns) {
        $table->unsignedBigInteger('tag_id');
        $table->morphs('taggable');
        $pivotColumns($table);
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(PFFParentResource::class);
    $registry->register(PFFTagResource::class);

    $this->parent = PFFParentModel::create(['name' => 'Parent']);
    $this->tag = PFFTagModel::create(['name' => 'Tag']);
});

afterEach(function () {
    foreach (['pff_taggables', 'pff_parent_tag', 'pff_tags', 'pff_parents'] as $table) {
        Schema::dropIfExists($table);
    }
});

$pivotEndpoints = ['belongs-to-many', 'morph-to-many'];

it('writes the pivot values of an attach through each field', function (string $endpoint) {
    $this->postJson("/martis/api/resources/pff-parents/{$this->parent->id}/{$endpoint}/".pffRelation($endpoint).'/attach', [
        'related_id' => $this->tag->id,
        'note' => 'shipped',
        'flags' => ['a', 'c'],
        'summary' => 'ignored',
        'featured' => true,
    ])->assertStatus(201);

    expect(pffStoredRow($endpoint))->toMatchArray([
        'note' => 'SHIPPED',
        'flags' => '["a","c"]',
        'featured' => 1,
    ]);
})->with($pivotEndpoints);

it('writes the pivot values of a batch attach through each field', function (string $endpoint) {
    $other = PFFTagModel::create(['name' => 'Other']);

    $this->postJson("/martis/api/resources/pff-parents/{$this->parent->id}/{$endpoint}/".pffRelation($endpoint).'/attach', [
        'related_ids' => [$this->tag->id, $other->id],
        'note' => 'bulk',
        'flags' => ['b'],
    ])->assertStatus(201);

    $rows = DB::table(pffTable($endpoint))->orderBy('tag_id')->get(['note', 'flags'])->map(fn ($row) => (array) $row)->all();
    expect($rows)->toBe([
        ['note' => 'BULK', 'flags' => '["b"]'],
        ['note' => 'BULK', 'flags' => '["b"]'],
    ]);
})->with($pivotEndpoints);

it('writes the pivot values of a pivot update through each field', function (string $endpoint) {
    $relation = pffRelation($endpoint);
    $this->parent->{$relation}()->attach($this->tag->id, ['note' => 'OLD', 'flags' => '["a"]', 'featured' => false]);

    $this->putJson("/martis/api/resources/pff-parents/{$this->parent->id}/{$endpoint}/{$relation}/{$this->tag->id}/pivot", [
        'note' => 'renamed',
        'flags' => ['b', 'c'],
        'summary' => 'ignored',
        'featured' => true,
    ])->assertStatus(200);

    expect(pffStoredRow($endpoint))->toMatchArray([
        'note' => 'RENAMED',
        'flags' => '["b","c"]',
        'featured' => 1,
    ]);
})->with($pivotEndpoints);

it('lets a custom pivot class cast a structured pivot field once, on attach and on update', function () {
    $base = "/martis/api/resources/pff-parents/{$this->parent->id}/belongs-to-many/castTags";

    $this->postJson("{$base}/attach", ['related_id' => $this->tag->id, 'flags' => ['a', 'b']])->assertStatus(201);

    expect(DB::table('pff_parent_tag')->value('flags'))->toBe('["a","b"]')
        ->and($this->parent->castTags()->first()->pivot->flags)->toBe(['a', 'b']);

    $this->putJson("{$base}/{$this->tag->id}/pivot", ['flags' => ['c']])->assertStatus(200);

    expect(DB::table('pff_parent_tag')->value('flags'))->toBe('["c"]')
        ->and($this->parent->castTags()->first()->pivot->flags)->toBe(['c']);
});
