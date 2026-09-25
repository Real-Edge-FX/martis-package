<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasOne as EloquentHasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\HasMany;
use Martis\Fields\HasOne;
use Martis\Fields\MorphMany;
use Martis\Fields\MorphOne;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A write through a relationship endpoint (has-many, has-one, morph-many,
 * morph-one) creates, updates or deletes a record of the related resource,
 * so it needs that resource's `viewAny`, as its own per-id endpoints do
 * (v1.34.0) and as Nova does. `routable()` is not required: a headless
 * resource (v1.24.0) stays usable as a relation target.
 */

class RWVParentModel extends Model
{
    protected $table = 'rwv_parents';

    protected $fillable = ['name'];

    public function children(): EloquentHasMany
    {
        return $this->hasMany(RWVChildModel::class, 'parent_id');
    }

    public function child(): EloquentHasOne
    {
        return $this->hasOne(RWVChildModel::class, 'parent_id');
    }

    public function notes(): EloquentMorphMany
    {
        return $this->morphMany(RWVNoteModel::class, 'notable');
    }

    public function note(): EloquentMorphOne
    {
        return $this->morphOne(RWVNoteModel::class, 'notable');
    }
}

class RWVChildModel extends Model
{
    protected $table = 'rwv_children';

    protected $fillable = ['title', 'parent_id'];
}

class RWVNoteModel extends Model
{
    protected $table = 'rwv_notes';

    protected $fillable = ['title', 'notable_type', 'notable_id'];
}

abstract class RWVRelatedResource extends Resource
{
    public function fields(Request $request): array
    {
        return [Text::make('title')->required()];
    }
}

class RWVHiddenChildResource extends RWVRelatedResource
{
    public static function model(): string
    {
        return RWVChildModel::class;
    }

    public static function uriKey(): string
    {
        return 'rwv-hidden-children';
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}

class RWVHiddenNoteResource extends RWVRelatedResource
{
    public static function model(): string
    {
        return RWVNoteModel::class;
    }

    public static function uriKey(): string
    {
        return 'rwv-hidden-notes';
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}

class RWVHeadlessChildResource extends RWVRelatedResource
{
    public static function model(): string
    {
        return RWVChildModel::class;
    }

    public static function uriKey(): string
    {
        return 'rwv-headless-children';
    }

    public static function routable(): bool
    {
        return false;
    }
}

class RWVHeadlessNoteResource extends RWVRelatedResource
{
    public static function model(): string
    {
        return RWVNoteModel::class;
    }

    public static function uriKey(): string
    {
        return 'rwv-headless-notes';
    }

    public static function routable(): bool
    {
        return false;
    }
}

abstract class RWVParentResource extends Resource
{
    public static function model(): string
    {
        return RWVParentModel::class;
    }

    abstract protected static function childKey(): string;

    abstract protected static function noteKey(): string;

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Children', 'children')->relatedResource(static::childKey()),
            HasOne::make('Child', 'child')->relatedResource(static::childKey()),
            MorphMany::make('Notes', 'notes')->relatedResource(static::noteKey()),
            MorphOne::make('Note', 'note')->relatedResource(static::noteKey()),
        ];
    }
}

class RWVHiddenParentResource extends RWVParentResource
{
    public static function uriKey(): string
    {
        return 'rwv-hidden-parents';
    }

    protected static function childKey(): string
    {
        return 'rwv-hidden-children';
    }

    protected static function noteKey(): string
    {
        return 'rwv-hidden-notes';
    }
}

class RWVHeadlessParentResource extends RWVParentResource
{
    public static function uriKey(): string
    {
        return 'rwv-headless-parents';
    }

    protected static function childKey(): string
    {
        return 'rwv-headless-children';
    }

    protected static function noteKey(): string
    {
        return 'rwv-headless-notes';
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['rwv_notes', 'rwv_children', 'rwv_parents'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('rwv_parents', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('rwv_children', function ($table) {
        $table->id();
        $table->unsignedBigInteger('parent_id');
        $table->string('title');
        $table->timestamps();
    });
    Schema::create('rwv_notes', function ($table) {
        $table->id();
        $table->morphs('notable');
        $table->string('title');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([RWVHiddenChildResource::class, RWVHiddenNoteResource::class, RWVHeadlessChildResource::class, RWVHeadlessNoteResource::class, RWVHiddenParentResource::class, RWVHeadlessParentResource::class] as $class) {
        $registry->register($class);
    }

    $this->withRecords = RWVParentModel::create(['name' => 'With records']);
    $this->withRecords->children()->create(['title' => 'Child']);
    $this->withRecords->notes()->create(['title' => 'Note']);
    $this->empty = RWVParentModel::create(['name' => 'Empty']);
});

afterEach(function () {
    foreach (['rwv_notes', 'rwv_children', 'rwv_parents'] as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
});

/** @return array<string, list<array<string, mixed>>> */
function rwvRows(): array
{
    return [
        'children' => DB::table('rwv_children')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'notes' => DB::table('rwv_notes')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
    ];
}

/** [method, parent ('withRecords' | 'empty'), path; `{child}` / `{note}` stand for the stored ids] */
dataset('rwv relationship writes', [
    'POST has-many store' => ['postJson', 'empty', 'has-many/children'],
    'PUT has-many update' => ['putJson', 'withRecords', 'has-many/children/{child}'],
    'DELETE has-many destroy' => ['deleteJson', 'withRecords', 'has-many/children/{child}'],
    'POST has-one store' => ['postJson', 'empty', 'has-one/child'],
    'PUT has-one update' => ['putJson', 'withRecords', 'has-one/child'],
    'DELETE has-one destroy' => ['deleteJson', 'withRecords', 'has-one/child'],
    'POST morph-many store' => ['postJson', 'empty', 'morph-many/notes'],
    'PUT morph-many update' => ['putJson', 'withRecords', 'morph-many/notes/{note}'],
    'DELETE morph-many destroy' => ['deleteJson', 'withRecords', 'morph-many/notes/{note}'],
    'POST morph-one store' => ['postJson', 'empty', 'morph-one/note'],
    'PUT morph-one update' => ['putJson', 'withRecords', 'morph-one/note'],
    'DELETE morph-one destroy' => ['deleteJson', 'withRecords', 'morph-one/note'],
]);

function rwvUrl(string $resource, RWVParentModel $parent, string $path): string
{
    return "/martis/api/resources/{$resource}/{$parent->id}/".strtr($path, [
        '{child}' => (string) RWVChildModel::query()->value('id'),
        '{note}' => (string) RWVNoteModel::query()->value('id'),
    ]);
}

it('refuses a write through a relationship when the related resource denies viewAny', function (string $method, string $parent, string $path) {
    $before = rwvRows();

    $this->{$method}(cardWriteUrl(rwvUrl('rwv-hidden-parents', $this->{$parent}, $path)), ['title' => 'Written'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'This action is unauthorized.');

    expect(rwvRows())->toBe($before);
})->with('rwv relationship writes');

it('writes through a relationship to a related resource that is not routable', function (string $method, string $parent, string $path) {
    $response = $this->{$method}(cardWriteUrl(rwvUrl('rwv-headless-parents', $this->{$parent}, $path)), ['title' => 'Written']);

    expect($response->status())->toBeIn([200, 201])
        ->and(rwvRows())->not->toBe([]);
})->with('rwv relationship writes');

// Those writes now always answer 403, so the panel offers none of them:
// Create, Edit and Delete, and Restore / Force delete (the related
// resource's own endpoints, gated on viewAny since v1.34.0). The panel
// still lists the records, as in 1.x.

function rwvPanelMeta(string $resource): array
{
    return collect(test()->getJson("/martis/api/resources/{$resource}/schema")->assertStatus(200)->json('data.fieldsForDetail'))
        ->filter(fn (array $field) => isset($field['relationship']))
        ->mapWithKeys(fn (array $field) => [$field['relationship'] => $field['hasManyMeta'] ?? $field['hasOneMeta'] ?? $field['morphManyMeta'] ?? $field['morphOneMeta']])
        ->all();
}

it('leaves a panel whose related resource denies viewAny off the detail page', function () {
    // As in Nova, a relationship field whose related resource the user may
    // not viewAny is not on the detail page at all (v2.0.1), so it offers
    // no write action either.
    expect(rwvPanelMeta('rwv-hidden-parents'))->toBe([]);
});

it('keeps the write actions of a panel whose related resource allows viewAny', function () {
    $metas = rwvPanelMeta('rwv-headless-parents');

    expect($metas)->toHaveCount(4);
    foreach ($metas as $relationship => $meta) {
        expect($meta, $relationship)->toMatchArray(['canCreate' => true, 'canUpdate' => true, 'canDelete' => true, 'hideRestoreAction' => false, 'hideForceDeleteAction' => false]);
    }
});
