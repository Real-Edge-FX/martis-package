<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasOne as EloquentHasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Martis\Fields\BelongsTo;
use Martis\Fields\HasMany;
use Martis\Fields\HasOne;
use Martis\Fields\MorphMany;
use Martis\Fields\MorphOne;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// A BelongsTo value sent as a map (v1.39.6).
//
// A create form opened from a relation panel pre-fills the BelongsTo back to
// the parent with `{ id, title }`. The write endpoints reduce a map whose
// `id` is an integer or a non-empty string to that id before the consumer's
// rules and the fill run, so `Rule::exists()` receives a key and not an
// array (a multi-value whereIn). Any other shape is left untouched.
// ===========================================================================

class BTMParentModel extends Model
{
    protected $table = 'btm_parents';

    protected $guarded = [];

    public $timestamps = false;

    public function children(): EloquentHasMany
    {
        return $this->hasMany(BTMChildModel::class, 'parent_id');
    }

    public function child(): EloquentHasOne
    {
        return $this->hasOne(BTMChildModel::class, 'parent_id');
    }

    public function notes(): EloquentMorphMany
    {
        return $this->morphMany(BTMChildModel::class, 'notable');
    }

    public function note(): EloquentMorphOne
    {
        return $this->morphOne(BTMChildModel::class, 'notable');
    }
}

class BTMChildModel extends Model
{
    protected $table = 'btm_children';

    protected $guarded = [];

    public $timestamps = false;
}

class BTMParentResource extends Resource
{
    public static function model(): string
    {
        return BTMParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'btm-parents';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Children', 'children')->relatedResource('btm-children'),
            HasOne::make('Child', 'child')->relatedResource('btm-children'),
            MorphMany::make('Notes', 'notes')->relatedResource('btm-children'),
            MorphOne::make('Note', 'note')->relatedResource('btm-children'),
        ];
    }
}

class BTMChildResource extends Resource
{
    public static function model(): string
    {
        return BTMChildModel::class;
    }

    public static function uriKey(): string
    {
        return 'btm-children';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('code'),
            BelongsTo::make('parent_id', 'Parent')
                ->relatedResource('btm-parents')
                ->rules(['nullable', Rule::exists('btm_parents', 'id')]),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('btm_children');
    Schema::dropIfExists('btm_parents');
    Schema::create('btm_parents', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('btm_children', function ($table) {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->nullableMorphs('notable');
        $table->string('code')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(BTMParentResource::class);
    $registry->register(BTMChildResource::class);
});

afterEach(function () {
    Schema::dropIfExists('btm_children');
    Schema::dropIfExists('btm_parents');
});

it('reduces a { id, title } BelongsTo value to the id on every create endpoint', function (string $path, bool $nested) {
    $parent = BTMParentModel::create(['name' => 'Parent']);
    $url = $nested ? "/martis/api/resources/btm-parents/{$parent->id}/{$path}" : '/martis/api/resources/btm-children';

    $this->postJson($url, ['code' => 'C-1', 'parent_id' => ['id' => $parent->id, 'title' => 'Some Title']])->assertCreated();

    expect(BTMChildModel::where('code', 'C-1')->value('parent_id'))->toBe($parent->id);
})->with([
    'resource' => ['', false],
    'has-many' => ['has-many/children', true],
    'has-one' => ['has-one/child', true],
    'morph-many' => ['morph-many/notes', true],
    'morph-one' => ['morph-one/note', true],
]);

it('accepts the id as a string and reduces it on update', function () {
    $parent = BTMParentModel::create(['name' => 'Parent']);
    $child = BTMChildModel::create(['code' => 'C-1']);

    $this->putJson("/martis/api/resources/btm-children/{$child->id}", ['parent_id' => ['id' => (string) $parent->id, 'title' => 'T']])->assertOk();

    expect($child->fresh()->parent_id)->toBe($parent->id);
});

it('still validates the reduced id against the rule', function () {
    $response = $this->postJson('/martis/api/resources/btm-children', ['code' => 'C-1', 'parent_id' => ['id' => 999, 'title' => 'Ghost']]);

    $response->assertStatus(422);
    expect(BTMChildModel::count())->toBe(0);
});

it('leaves a map without a usable id untouched', function (array $value) {
    $this->postJson('/martis/api/resources/btm-children', ['code' => 'C-1', 'parent_id' => $value])->assertStatus(422);

    expect(BTMChildModel::count())->toBe(0);
})->with([
    'no id' => [['title' => 'Only title']],
    'empty id' => [['id' => '', 'title' => 'T']],
    'boolean id' => [['id' => true, 'title' => 'T']],
    'list' => [[1, 2]],
]);
