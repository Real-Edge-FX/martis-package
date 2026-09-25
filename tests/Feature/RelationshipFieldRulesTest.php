<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasOne as EloquentHasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Rule;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\HasOne;
use Martis\Fields\MorphMany;
use Martis\Fields\MorphOne;
use Martis\Fields\MorphToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// Field rules on the relationship write endpoints.
//
// The HasMany / HasOne / MorphMany / MorphOne inline forms and the
// BelongsToMany / MorphToMany pivot forms validate with the rules the
// related resource (or the pivot field) declares, exactly as the resource's
// own create and update endpoints do. Every test below also runs against
// the resource endpoint, the reference the relationship endpoints match.
//
// On update only the literal `required` string is dropped and `sometimes`
// leads the list, so a key the request does not send runs no rule. Every
// other rule is kept: `Rule::` builder objects, `ValidationRule`
// instances, closures, implicit rule objects. `creationRules()` apply on
// create (and attach), `updateRules()` on update.
//
// Up to v1.37.3 the relationship updates kept string rules only: the pivot
// updates dropped every rule object and closure, the inline updates dropped
// closures and `Rule::enum()` / `Rule::requiredIf()` objects and answered
// any update of a resource with a `ValidationRule` field with a 500, and no
// relationship endpoint applied `creationRules()` / `updateRules()`.
// ===========================================================================

enum RFRKind: string
{
    case Article = 'article';
    case Video = 'video';
}

class RFRReservedWordRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === 'admin') {
            $fail('The :attribute is reserved.');
        }
    }
}

/**
 * The fields every related record and every pivot row declares: one rule of
 * each kind, plus rules scoped to create and to update.
 *
 * @return list<Text>
 */
function rfrRuleFields(): array
{
    return [
        Text::make('title')->rules([Rule::requiredIf(true)]),
        Text::make('status')->rules([Rule::in(['draft', 'published'])]),
        Text::make('kind')->rules([Rule::enum(RFRKind::class)]),
        Text::make('slug')->rules([function (string $attribute, mixed $value, Closure $fail): void {
            if (str_contains((string) $value, ' ')) {
                $fail('The :attribute may not contain spaces.');
            }
        }]),
        Text::make('owner')->rules([new RFRReservedWordRule]),
        Text::make('stage')
            ->creationRules([Rule::in(['new'])])
            ->updateRules([Rule::in(['review', 'done'])]),
    ];
}

class RFRParentModel extends Model
{
    protected $table = 'rfr_parents';

    protected $guarded = [];

    public $timestamps = false;

    public function children(): EloquentHasMany
    {
        return $this->hasMany(RFRChildModel::class, 'parent_id');
    }

    public function child(): EloquentHasOne
    {
        return $this->hasOne(RFRChildModel::class, 'parent_id');
    }

    public function notes(): EloquentMorphMany
    {
        return $this->morphMany(RFRNoteModel::class, 'notable');
    }

    public function note(): EloquentMorphOne
    {
        return $this->morphOne(RFRNoteModel::class, 'notable');
    }

    public function tags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(RFRTagModel::class, 'rfr_parent_tag', 'parent_id', 'tag_id')
            ->withPivot(['title', 'status', 'kind', 'slug', 'owner', 'stage']);
    }

    public function labels(): EloquentMorphToMany
    {
        return $this->morphToMany(RFRTagModel::class, 'taggable', 'rfr_taggables', null, 'tag_id')
            ->withPivot(['title', 'status', 'kind', 'slug', 'owner', 'stage']);
    }
}

class RFRChildModel extends Model
{
    protected $table = 'rfr_children';

    protected $guarded = [];

    public $timestamps = false;
}

class RFRNoteModel extends Model
{
    protected $table = 'rfr_notes';

    protected $guarded = [];

    public $timestamps = false;
}

class RFRTagModel extends Model
{
    protected $table = 'rfr_tags';

    protected $guarded = [];

    public $timestamps = false;
}

class RFRParentResource extends Resource
{
    public static function model(): string
    {
        return RFRParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'rfr-parents';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Children', 'children')->relatedResource('rfr-children'),
            HasOne::make('Child', 'child')->relatedResource('rfr-children'),
            MorphMany::make('Notes', 'notes')->relatedResource('rfr-notes'),
            MorphOne::make('Note', 'note')->relatedResource('rfr-notes'),
            BelongsToMany::make('Tags', 'tags')->relatedResource('rfr-tags')->fields(fn () => rfrRuleFields()),
            MorphToMany::make('Labels', 'labels')->relatedResource('rfr-tags')->fields(fn () => rfrRuleFields()),
        ];
    }
}

class RFRChildResource extends Resource
{
    public static function model(): string
    {
        return RFRChildModel::class;
    }

    public static function uriKey(): string
    {
        return 'rfr-children';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('code')->required()->rules([Rule::unique('rfr_children', 'code')->ignore($this->model?->getKey())]),
            ...rfrRuleFields(),
        ];
    }
}

class RFRNoteResource extends Resource
{
    public static function model(): string
    {
        return RFRNoteModel::class;
    }

    public static function uriKey(): string
    {
        return 'rfr-notes';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('code')->required()->rules([Rule::unique('rfr_notes', 'code')->ignore($this->model?->getKey())]),
            ...rfrRuleFields(),
        ];
    }
}

class RFRTagResource extends Resource
{
    public static function model(): string
    {
        return RFRTagModel::class;
    }

    public static function uriKey(): string
    {
        return 'rfr-tags';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

function rfrDropTables(): void
{
    foreach (['rfr_taggables', 'rfr_parent_tag', 'rfr_tags', 'rfr_notes', 'rfr_children', 'rfr_parents'] as $table) {
        Schema::dropIfExists($table);
    }
}

/**
 * Create the record (or the pivot row) an update endpoint edits, and return
 * the update URL with a reader for the values stored for it.
 *
 * @return array{0: string, 1: Closure(): array<string, mixed>}
 */
function rfrUpdateTarget(string $endpoint): array
{
    $values = ['title' => 'First', 'status' => 'draft', 'kind' => 'article', 'slug' => 'first', 'owner' => 'alice', 'stage' => 'new'];

    if ($endpoint === 'resource') {
        $record = RFRChildModel::create(['code' => 'C-1'] + $values);

        return [
            "/martis/api/resources/rfr-children/{$record->id}",
            fn (): array => Arr::only($record->fresh()->getAttributes(), ['code', ...array_keys($values)]),
        ];
    }

    $parent = RFRParentModel::create(['name' => 'Parent']);
    $base = "/martis/api/resources/rfr-parents/{$parent->id}/{$endpoint}";

    if ($endpoint === 'belongs-to-many' || $endpoint === 'morph-to-many') {
        $relation = $endpoint === 'belongs-to-many' ? 'tags' : 'labels';
        $tag = RFRTagModel::create(['name' => 'Tag']);
        $parent->{$relation}()->attach($tag->id, $values);

        return [
            "{$base}/{$relation}/{$tag->id}/pivot",
            fn (): array => Arr::only($parent->{$relation}()->first()->pivot->getAttributes(), array_keys($values)),
        ];
    }

    [$relation, $path] = match ($endpoint) {
        'has-many' => ['children', 'children/%d'],
        'has-one' => ['child', 'child'],
        'morph-many' => ['notes', 'notes/%d'],
        'morph-one' => ['note', 'note'],
    };
    $record = $parent->{$relation}()->create(['code' => 'C-1'] + $values);

    return [
        "{$base}/".sprintf($path, $record->id),
        fn (): array => Arr::only($record->fresh()->getAttributes(), ['code', ...array_keys($values)]),
    ];
}

/**
 * Return the create (or attach) URL of an endpoint with the rest of a valid
 * body.
 *
 * @return array{0: string, 1: array<string, mixed>}
 */
function rfrCreateTarget(string $endpoint): array
{
    $valid = ['title' => 'New'];

    if ($endpoint === 'resource') {
        return ['/martis/api/resources/rfr-children', $valid + ['code' => 'C-9']];
    }

    $parent = RFRParentModel::create(['name' => 'Parent']);
    $base = "/martis/api/resources/rfr-parents/{$parent->id}/{$endpoint}";

    return match ($endpoint) {
        'has-many' => ["{$base}/children", $valid + ['code' => 'C-9']],
        'has-one' => ["{$base}/child", $valid + ['code' => 'C-9']],
        'morph-many' => ["{$base}/notes", $valid + ['code' => 'C-9']],
        'morph-one' => ["{$base}/note", $valid + ['code' => 'C-9']],
        'belongs-to-many' => ["{$base}/tags/attach", $valid + ['related_id' => RFRTagModel::create(['name' => 'Tag'])->id]],
        'morph-to-many' => ["{$base}/labels/attach", $valid + ['related_id' => RFRTagModel::create(['name' => 'Tag'])->id]],
    };
}

/**
 * @return list<string>
 */
function rfrErrorFields(TestResponse $response): array
{
    return collect($response->json('errors'))->pluck('field')->unique()->values()->all();
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    rfrDropTables();

    $ruleColumns = function ($table): void {
        foreach (['title', 'status', 'kind', 'slug', 'owner', 'stage'] as $column) {
            $table->string($column)->nullable();
        }
    };

    Schema::create('rfr_parents', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('rfr_children', function ($table) use ($ruleColumns) {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->string('code')->nullable();
        $ruleColumns($table);
    });
    Schema::create('rfr_notes', function ($table) use ($ruleColumns) {
        $table->id();
        $table->nullableMorphs('notable');
        $table->string('code')->nullable();
        $ruleColumns($table);
    });
    Schema::create('rfr_tags', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('rfr_parent_tag', function ($table) use ($ruleColumns) {
        $table->unsignedBigInteger('parent_id');
        $table->unsignedBigInteger('tag_id');
        $ruleColumns($table);
    });
    Schema::create('rfr_taggables', function ($table) use ($ruleColumns) {
        $table->id();
        $table->unsignedBigInteger('tag_id');
        $table->morphs('taggable');
        $ruleColumns($table);
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(RFRParentResource::class);
    $registry->register(RFRChildResource::class);
    $registry->register(RFRNoteResource::class);
    $registry->register(RFRTagResource::class);
});

afterEach(function () {
    rfrDropTables();
});

$endpoints = ['resource', 'has-many', 'has-one', 'morph-many', 'morph-one', 'belongs-to-many', 'morph-to-many'];

// ---------------------------------------------------------------------------
// Update: every kind of rule runs
// ---------------------------------------------------------------------------

it('runs every kind of field rule on the update', function (string $endpoint, string $attribute, string $invalid) {
    [$url, $stored] = rfrUpdateTarget($endpoint);
    $before = $stored();

    $response = $this->putJson(cardWriteUrl($url), [$attribute => $invalid]);

    $response->assertStatus(422);
    expect(rfrErrorFields($response))->toBe([$attribute])
        ->and($stored())->toBe($before);
})->with($endpoints)->with([
    'a Rule::in() object' => ['status', 'archived'],
    'a Rule::enum() object' => ['kind', 'podcast'],
    'a closure' => ['slug', 'with spaces'],
    'a ValidationRule instance' => ['owner', 'admin'],
    'an implicit Rule::requiredIf() object' => ['title', ''],
]);

it('runs no rule for a key the update does not send and stores the values every rule allows', function (string $endpoint) {
    [$url, $stored] = rfrUpdateTarget($endpoint);
    $before = $stored();

    // Every other key carries a rule object, a closure, a ValidationRule, an
    // implicit Rule::requiredIf() or context rules, and on the resource and
    // inline forms `code` is required() with a Rule::unique(). `sometimes`
    // leads each list on update, so none of them runs for a key not sent.
    $this->putJson(cardWriteUrl($url), ['status' => 'published'])->assertStatus(200);
    expect($stored())->toBe(array_replace($before, ['status' => 'published']));

    $values = ['title' => 'Second', 'status' => 'draft', 'kind' => 'video', 'slug' => 'second', 'owner' => 'bob', 'stage' => 'review'];
    $this->putJson(cardWriteUrl($url), $values)->assertStatus(200);
    expect($stored())->toMatchArray($values);
})->with($endpoints);

it('runs a Rule::unique() object that ignores the edited record', function (string $endpoint) {
    [$url, $stored] = rfrUpdateTarget($endpoint);

    $other = RFRParentModel::create(['name' => 'Other']);
    match ($endpoint) {
        'resource', 'has-many', 'has-one' => $other->children()->create(['code' => 'C-2']),
        'morph-many', 'morph-one' => $other->notes()->create(['code' => 'C-2']),
    };

    $taken = $this->putJson(cardWriteUrl($url), ['code' => 'C-2']);
    $taken->assertStatus(422);
    expect(rfrErrorFields($taken))->toBe(['code']);

    // The edited record's own code does not collide with itself.
    $this->putJson(cardWriteUrl($url), ['code' => 'C-1'])->assertStatus(200);
    $this->putJson(cardWriteUrl($url), ['code' => 'C-3'])->assertStatus(200);
    expect($stored()['code'])->toBe('C-3');
})->with(['resource', 'has-many', 'has-one', 'morph-many', 'morph-one']);

// ---------------------------------------------------------------------------
// creationRules() / updateRules()
// ---------------------------------------------------------------------------

it('applies creationRules and not updateRules on create', function (string $endpoint) {
    [$url, $body] = rfrCreateTarget($endpoint);

    $rejected = $this->postJson($url, $body + ['stage' => 'done']);
    $rejected->assertStatus(422);
    expect(rfrErrorFields($rejected))->toBe(['stage']);

    $this->postJson($url, $body + ['stage' => 'new'])->assertStatus(201);
})->with($endpoints);

it('applies updateRules and not creationRules on update', function (string $endpoint) {
    [$url, $stored] = rfrUpdateTarget($endpoint);

    $rejected = $this->putJson(cardWriteUrl($url), ['stage' => 'new']);
    $rejected->assertStatus(422);
    expect(rfrErrorFields($rejected))->toBe(['stage']);

    $this->putJson(cardWriteUrl($url), ['stage' => 'done'])->assertStatus(200);
    expect($stored()['stage'])->toBe('done');
})->with($endpoints);
