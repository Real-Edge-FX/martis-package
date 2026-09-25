<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\HasMany;
use Martis\Fields\MorphMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A relationship panel offers the related resource's row actions, as the
 * resource index does, so each row it lists carries the same per-action
 * `canRun` map (`_actionAuthorization`) the index rows carry.
 */

class RPAParentModel extends Model
{
    protected $table = 'rpa_parents';

    protected $fillable = ['name'];

    public function tasks(): EloquentHasMany
    {
        return $this->hasMany(RPATaskModel::class, 'parent_id');
    }

    public function notes(): EloquentMorphMany
    {
        return $this->morphMany(RPANoteModel::class, 'notable');
    }
}

class RPATaskModel extends Model
{
    protected $table = 'rpa_tasks';

    protected $fillable = ['title', 'parent_id'];
}

class RPANoteModel extends Model
{
    protected $table = 'rpa_notes';

    protected $fillable = ['title', 'notable_type', 'notable_id'];
}

class RPACloseAction extends Action
{
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return null;
    }

    public function uriKey(): string
    {
        return 'rpa-close';
    }
}

class RPAExportAction extends RPACloseAction
{
    public function uriKey(): string
    {
        return 'rpa-export';
    }
}

// Not inline: the panel rows do not map it.
class RPABulkAction extends RPACloseAction
{
    public function uriKey(): string
    {
        return 'rpa-bulk';
    }
}

abstract class RPARelatedResource extends Resource
{
    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }

    public function actions(Request $request): array
    {
        return [
            (new RPACloseAction)->showInline()->canRun(fn ($request, $model) => $model->title !== 'Locked'),
            (new RPAExportAction)->showInline()->standalone(),
            new RPABulkAction,
        ];
    }
}

class RPATaskResource extends RPARelatedResource
{
    public static function model(): string
    {
        return RPATaskModel::class;
    }

    public static function uriKey(): string
    {
        return 'rpa-tasks';
    }
}

class RPANoteResource extends RPARelatedResource
{
    public static function model(): string
    {
        return RPANoteModel::class;
    }

    public static function uriKey(): string
    {
        return 'rpa-notes';
    }
}

class RPAParentResource extends Resource
{
    public static function model(): string
    {
        return RPAParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'rpa-parents';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Tasks', 'tasks')->relatedResource('rpa-tasks'),
            MorphMany::make('Notes', 'notes')->relatedResource('rpa-notes'),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['rpa_notes', 'rpa_tasks', 'rpa_parents'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('rpa_parents', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('rpa_tasks', function ($table) {
        $table->id();
        $table->unsignedBigInteger('parent_id');
        $table->string('title');
        $table->timestamps();
    });
    Schema::create('rpa_notes', function ($table) {
        $table->id();
        $table->morphs('notable');
        $table->string('title');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([RPATaskResource::class, RPANoteResource::class, RPAParentResource::class] as $class) {
        $registry->register($class);
    }

    $this->parent = RPAParentModel::create(['name' => 'Parent']);
    foreach (['Open', 'Locked'] as $title) {
        $this->parent->tasks()->create(['title' => $title]);
        $this->parent->notes()->create(['title' => $title]);
    }
});

afterEach(function () {
    foreach (['rpa_notes', 'rpa_tasks', 'rpa_parents'] as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
});

it('carries each row action\'s canRun on the rows of a relationship panel', function (string $path) {
    $rows = collect($this->getJson("/martis/api/resources/rpa-parents/{$this->parent->id}/{$path}")
        ->assertStatus(200)
        ->json('data'))->keyBy('title');

    expect($rows['Open']['_actionAuthorization'])->toBe(['rpa-close' => true, 'rpa-export' => true])
        ->and($rows['Locked']['_actionAuthorization'])->toBe(['rpa-close' => false, 'rpa-export' => true]);
})->with([
    'has-many index' => ['has-many/tasks'],
    'morph-many index' => ['morph-many/notes'],
]);

it('runs a row action through a morph-many relationship only on a record it holds', function () {
    $other = RPAParentModel::create(['name' => 'Other']);
    $foreign = $other->notes()->create(['title' => 'Foreign']);
    $own = $this->parent->notes()->where('title', 'Open')->firstOrFail();
    $via = ['viaResource' => 'rpa-parents', 'viaResourceId' => $this->parent->id, 'viaRelationship' => 'notes'];

    $this->postJson('/martis/api/resources/rpa-notes/actions/rpa-close', ['resources' => [$own->id]] + $via)->assertOk();
    $this->postJson('/martis/api/resources/rpa-notes/actions/rpa-close', ['resources' => [$foreign->id]] + $via)->assertStatus(404);
});
