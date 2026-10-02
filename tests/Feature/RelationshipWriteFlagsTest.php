<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasOne as EloquentHasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsTo;
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

/*
 * The setters canCreate(), canUpdate() and canDelete() of HasMany, HasOne,
 * MorphMany and MorphOne, and canAttach() and canDetach() of BelongsToMany
 * and MorphToMany, turn the write off on the endpoint, not only on the
 * panel's button: a request that names the relationship directly answers
 * 403 and writes nothing. The policies still gate every write a flag leaves
 * on.
 */

class RWFParentModel extends Model
{
    protected $table = 'rwf_parents';

    protected $fillable = ['name'];

    public function children(): EloquentHasMany
    {
        return $this->hasMany(RWFChildModel::class, 'parent_id');
    }

    public function child(): EloquentHasOne
    {
        return $this->hasOne(RWFChildModel::class, 'parent_id');
    }

    public function notes(): EloquentMorphMany
    {
        return $this->morphMany(RWFNoteModel::class, 'notable');
    }

    public function note(): EloquentMorphOne
    {
        return $this->morphOne(RWFNoteModel::class, 'notable');
    }

    public function tags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(RWFTagModel::class, 'rwf_parent_tag', 'parent_id', 'tag_id');
    }

    public function labels(): EloquentMorphToMany
    {
        return $this->morphToMany(RWFLabelModel::class, 'labelable', 'rwf_labelables', 'labelable_id', 'label_id');
    }
}

class RWFChildModel extends Model
{
    protected $table = 'rwf_children';

    protected $fillable = ['title', 'parent_id'];
}

class RWFNoteModel extends Model
{
    protected $table = 'rwf_notes';

    protected $fillable = ['title', 'notable_type', 'notable_id'];
}

class RWFTagModel extends Model
{
    protected $table = 'rwf_tags';

    protected $fillable = ['name'];
}

class RWFLabelModel extends Model
{
    protected $table = 'rwf_labels';

    protected $fillable = ['name'];
}

abstract class RWFTitledResource extends Resource
{
    public function fields(Request $request): array
    {
        return [Text::make('title')->required()];
    }
}

class RWFChildResource extends RWFTitledResource
{
    public static function model(): string
    {
        return RWFChildModel::class;
    }

    public static function uriKey(): string
    {
        return 'rwf-children';
    }
}

class RWFNoteResource extends RWFTitledResource
{
    public static function model(): string
    {
        return RWFNoteModel::class;
    }

    public static function uriKey(): string
    {
        return 'rwf-notes';
    }
}

class RWFTagResource extends Resource
{
    public static function model(): string
    {
        return RWFTagModel::class;
    }

    public static function uriKey(): string
    {
        return 'rwf-tags';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->required()];
    }
}

class RWFLabelResource extends Resource
{
    public static function model(): string
    {
        return RWFLabelModel::class;
    }

    public static function uriKey(): string
    {
        return 'rwf-labels';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->required()];
    }
}

/**
 * The parent resource: each subclass sets the flags of its six panels.
 * `$create`, `$update` and `$delete` drive the four one-to-many panels,
 * `$attach` and `$detach` the two many-to-many ones.
 */
abstract class RWFParentResource extends Resource
{
    protected bool $create = true;

    protected bool $update = true;

    protected bool $delete = true;

    protected bool $attach = true;

    protected bool $detach = true;

    public static function model(): string
    {
        return RWFParentModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Children', 'children')->relatedResource('rwf-children')
                ->canCreate($this->create)->canUpdate($this->update)->canDelete($this->delete),
            HasOne::make('Child', 'child')->relatedResource('rwf-children')
                ->canCreate($this->create)->canUpdate($this->update)->canDelete($this->delete),
            MorphMany::make('Notes', 'notes')->relatedResource('rwf-notes')
                ->canCreate($this->create)->canUpdate($this->update)->canDelete($this->delete),
            MorphOne::make('Note', 'note')->relatedResource('rwf-notes')
                ->canCreate($this->create)->canUpdate($this->update)->canDelete($this->delete),
            BelongsToMany::make('Tags', 'tags')->relatedResource('rwf-tags')
                ->canAttach($this->attach)->canDetach($this->detach)
                ->fields(fn () => [BelongsTo::make('owner', 'Owner')->relatedResource('rwf-tags')->titleAttribute('name')->nullable()]),
            MorphToMany::make('Labels', 'labels')->relatedResource('rwf-labels')
                ->canAttach($this->attach)->canDetach($this->detach),
        ];
    }
}

/** Every flag off. */
class RWFClosedParentResource extends RWFParentResource
{
    protected bool $create = false;

    protected bool $update = false;

    protected bool $delete = false;

    protected bool $attach = false;

    protected bool $detach = false;

    public static function uriKey(): string
    {
        return 'rwf-closed-parents';
    }
}

/** Create and attach on; update, delete and detach off. */
class RWFPartialParentResource extends RWFParentResource
{
    protected bool $update = false;

    protected bool $delete = false;

    protected bool $detach = false;

    public static function uriKey(): string
    {
        return 'rwf-partial-parents';
    }
}

/** Every flag at its default (on). */
class RWFOpenParentResource extends RWFParentResource
{
    public static function uriKey(): string
    {
        return 'rwf-open-parents';
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['rwf_labelables', 'rwf_labels', 'rwf_parent_tag', 'rwf_tags', 'rwf_notes', 'rwf_children', 'rwf_parents'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('rwf_parents', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('rwf_children', function ($table) {
        $table->id();
        $table->unsignedBigInteger('parent_id');
        $table->string('title');
        $table->timestamps();
    });
    Schema::create('rwf_notes', function ($table) {
        $table->id();
        $table->morphs('notable');
        $table->string('title');
        $table->timestamps();
    });
    Schema::create('rwf_tags', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('rwf_parent_tag', function ($table) {
        $table->id();
        $table->unsignedBigInteger('parent_id');
        $table->unsignedBigInteger('tag_id');
        $table->unsignedBigInteger('owner_id')->nullable();
        $table->timestamps();
    });
    Schema::create('rwf_labels', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('rwf_labelables', function ($table) {
        $table->id();
        $table->unsignedBigInteger('label_id');
        $table->morphs('labelable');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([RWFChildResource::class, RWFNoteResource::class, RWFTagResource::class, RWFLabelResource::class, RWFClosedParentResource::class, RWFPartialParentResource::class, RWFOpenParentResource::class] as $class) {
        $registry->register($class);
    }

    $this->withRecords = RWFParentModel::create(['name' => 'With records']);
    $this->withRecords->children()->create(['title' => 'Child']);
    $this->withRecords->notes()->create(['title' => 'Note']);
    $this->attachedTag = RWFTagModel::create(['name' => 'Attached']);
    $this->withRecords->tags()->attach($this->attachedTag->id);
    $this->attachedLabel = RWFLabelModel::create(['name' => 'Attached']);
    $this->withRecords->labels()->attach($this->attachedLabel->id);

    $this->empty = RWFParentModel::create(['name' => 'Empty']);
    $this->freeTag = RWFTagModel::create(['name' => 'Free']);
    $this->freeLabel = RWFLabelModel::create(['name' => 'Free']);
});

afterEach(function () {
    foreach (['rwf_labelables', 'rwf_labels', 'rwf_parent_tag', 'rwf_tags', 'rwf_notes', 'rwf_children', 'rwf_parents'] as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
});

/** Every table a write through a relationship panel can touch. @return array<string, list<array<string, mixed>>> */
function rwfRows(): array
{
    $rows = [];
    foreach (['rwf_children', 'rwf_notes', 'rwf_parent_tag', 'rwf_labelables'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    return $rows;
}

function rwfUrl(string $resource, RWFParentModel $parent, string $path): string
{
    return "/martis/api/resources/{$resource}/{$parent->id}/".strtr($path, [
        '{child}' => (string) RWFChildModel::query()->value('id'),
        '{note}' => (string) RWFNoteModel::query()->value('id'),
        '{tag}' => (string) RWFTagModel::query()->where('name', 'Attached')->value('id'),
        '{label}' => (string) RWFLabelModel::query()->where('name', 'Attached')->value('id'),
    ]);
}

/**
 * The write bodies. `{free}` stands for the id of the unattached tag or label.
 *
 * [method, parent ('withRecords' | 'empty'), path, flag the write needs, body]
 */
dataset('rwf writes', [
    'POST has-many store' => ['postJson', 'empty', 'has-many/children', 'create', ['title' => 'Written']],
    'PUT has-many update' => ['putJson', 'withRecords', 'has-many/children/{child}', 'update', ['title' => 'Written']],
    'DELETE has-many destroy' => ['deleteJson', 'withRecords', 'has-many/children/{child}', 'delete', []],
    'POST has-one store' => ['postJson', 'empty', 'has-one/child', 'create', ['title' => 'Written']],
    'PUT has-one update' => ['putJson', 'withRecords', 'has-one/child', 'update', ['title' => 'Written']],
    'DELETE has-one destroy' => ['deleteJson', 'withRecords', 'has-one/child', 'delete', []],
    'POST morph-many store' => ['postJson', 'empty', 'morph-many/notes', 'create', ['title' => 'Written']],
    'PUT morph-many update' => ['putJson', 'withRecords', 'morph-many/notes/{note}', 'update', ['title' => 'Written']],
    'DELETE morph-many destroy' => ['deleteJson', 'withRecords', 'morph-many/notes/{note}', 'delete', []],
    'POST morph-one store' => ['postJson', 'empty', 'morph-one/note', 'create', ['title' => 'Written']],
    'PUT morph-one update' => ['putJson', 'withRecords', 'morph-one/note', 'update', ['title' => 'Written']],
    'DELETE morph-one destroy' => ['deleteJson', 'withRecords', 'morph-one/note', 'delete', []],
    'POST belongs-to-many attach' => ['postJson', 'empty', 'belongs-to-many/tags/attach', 'attach', ['related_id' => '{freeTag}']],
    'POST belongs-to-many batch attach' => ['postJson', 'empty', 'belongs-to-many/tags/attach', 'attach', ['related_ids' => ['{freeTag}']]],
    'GET belongs-to-many attachable' => ['getJson', 'empty', 'belongs-to-many/tags/attachable', 'attach', []],
    'DELETE belongs-to-many detach' => ['deleteJson', 'withRecords', 'belongs-to-many/tags/{tag}/detach', 'detach', []],
    'POST morph-to-many attach' => ['postJson', 'empty', 'morph-to-many/labels/attach', 'attach', ['related_id' => '{freeLabel}']],
    'POST morph-to-many batch attach' => ['postJson', 'empty', 'morph-to-many/labels/attach', 'attach', ['related_ids' => ['{freeLabel}']]],
    'GET morph-to-many attachable' => ['getJson', 'empty', 'morph-to-many/labels/attachable', 'attach', []],
    'DELETE morph-to-many detach' => ['deleteJson', 'withRecords', 'morph-to-many/labels/{label}/detach', 'detach', []],
]);

function rwfSend($test, string $method, string $resource, string $parent, string $path, array $body)
{
    $body = json_decode(strtr((string) json_encode($body), [
        '"{freeTag}"' => (string) $test->freeTag->id,
        '"{freeLabel}"' => (string) $test->freeLabel->id,
    ]), true);

    $url = cardWriteUrl(rwfUrl($resource, $test->{$parent}, $path));

    return $method === 'getJson' ? $test->getJson($url) : $test->{$method}($url, $body);
}

it('refuses every write through a panel whose flags are all off, and writes nothing', function (string $method, string $parent, string $path, string $flag, array $body) {
    $before = rwfRows();

    rwfSend($this, $method, 'rwf-closed-parents', $parent, $path, $body)
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');

    expect(rwfRows())->toBe($before);
})->with('rwf writes');

it('refuses only the writes a panel turned off', function (string $method, string $parent, string $path, string $flag, array $body) {
    $before = rwfRows();

    $response = rwfSend($this, $method, 'rwf-partial-parents', $parent, $path, $body);

    if (in_array($flag, ['create', 'attach'], true)) {
        // On: the write goes through, under the policies (none here).
        $response->assertSuccessful();
        if ($method !== 'getJson') {
            expect(rwfRows())->not->toBe($before);
        }
    } else {
        $response->assertForbidden();
        expect(rwfRows())->toBe($before);
    }
})->with('rwf writes');

it('lets every write through a panel whose flags are on', function (string $method, string $parent, string $path, string $flag, array $body) {
    rwfSend($this, $method, 'rwf-open-parents', $parent, $path, $body)->assertSuccessful();
})->with('rwf writes');

it('refuses the attach modal pickers of a panel that turned attach off', function () {
    $closed = '/martis/api/resources/rwf-closed-parents/'.$this->empty->id.'/belongs-to-many/tags/pivot-fields/relatable/owner_id';
    $open = '/martis/api/resources/rwf-open-parents/'.$this->empty->id.'/belongs-to-many/tags/pivot-fields/relatable/owner_id';

    $this->getJson($closed)->assertForbidden();
    $this->getJson($open)->assertOk();
});

it('keeps the pivot edit picker of a panel that turned attach and detach off', function () {
    // The pivot edit modal follows authorizedToUpdatePivot(), not the attach flag.
    $url = '/martis/api/resources/rwf-closed-parents/'.$this->withRecords->id.'/belongs-to-many/tags/pivot-fields/'.$this->attachedTag->id.'/relatable/owner_id';

    $this->getJson($url)->assertOk();
});

it('exposes what each field allows', function () {
    expect(HasMany::make('Children', 'children')->allowsRelatedWrite('create'))->toBeTrue()
        ->and(HasMany::make('Children', 'children')->canCreate(false)->allowsRelatedWrite('create'))->toBeFalse()
        ->and(HasMany::make('Children', 'children')->canCreate(false)->allowsRelatedWrite('update'))->toBeTrue()
        ->and(HasOne::make('Child', 'child')->canUpdate(false)->allowsRelatedWrite('update'))->toBeFalse()
        ->and(MorphMany::make('Notes', 'notes')->canDelete(false)->allowsRelatedWrite('delete'))->toBeFalse()
        ->and(MorphOne::make('Note', 'note')->canDelete(false)->allowsRelatedWrite('delete'))->toBeFalse()
        ->and(BelongsToMany::make('Tags', 'tags')->canAttach(false)->allowsAttach())->toBeFalse()
        ->and(BelongsToMany::make('Tags', 'tags')->canDetach(false)->allowsDetach())->toBeFalse()
        ->and(MorphToMany::make('Labels', 'labels')->canAttach(false)->allowsAttach())->toBeFalse()
        ->and(MorphToMany::make('Labels', 'labels')->canDetach(false)->allowsDetach())->toBeFalse();
});
