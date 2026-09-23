<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\Repeatable;
use Martis\Fields\Repeater;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// A Repeater row writes only the row fields its form can change (v1.38.0).
//
// `readonly()` on a field inside a Repeatable only reached the form, and
// `computed()`, `canSee()` and `immutable()` had no effect there: every
// storage mode wrote each value a row sent, so a request could set a readonly
// or computed row field, change an immutable one and write one the user
// cannot see, and the Repeatable serialised every field, each row carrying
// the value of every field, the hidden ones included. HasMany and polymorphic
// rows were matched by a unique field the form never sends, so every save
// recreated the children.
//
// A row now continues the stored row whose id it carries: a field it cannot
// write (readonly, computed, hidden from the user, immutable on a stored row)
// keeps the stored row's value, and on a new row takes its default() or
// nothing. A field the user cannot see is left out of the schema, of the row
// values and of the validation, and so is an immutable field of a stored row.
// ===========================================================================

/** The fields of every row type below, whatever its storage. */
function rfaRowFields(): array
{
    return [
        Text::make('name', 'Name')->required(),
        Text::make('code', 'Code')->readonly(),
        Text::make('status', 'Status')->readonly()->default('draft'),
        Text::make('total', 'Total')->computed(fn () => 'computed'),
        Text::make('secret', 'Secret')->canSee(fn () => false)->rules(['required']),
        Text::make('tier', 'Tier')->canSee(fn () => false)->default('basic'),
        Text::make('slug', 'Slug')->immutable()->nullable()->rules(['max:8']),
    ];
}

/** What a forged request sends for every row field the row cannot write. */
const RFA_FORGED = ['code' => 'forged', 'status' => 'forged', 'total' => 'forged', 'secret' => 'forged', 'tier' => 'forged'];

class RFASection extends Repeatable
{
    public function fields(Request $request): array
    {
        return rfaRowFields();
    }
}

class RFALink extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            Text::make('url', 'URL')->required(),
            Text::make('code', 'Code')->readonly(),
            Text::make('secret', 'Secret')->canSee(fn () => false),
        ];
    }
}

class RFAMenu extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            Text::make('label', 'Label')->required(),
            Repeater::make('links', 'Links')->repeatables([RFALink::make()]),
        ];
    }
}

class RFAPageModel extends Model
{
    protected $table = 'rfa_pages';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['sections' => 'array', 'keyed' => 'array', 'menus' => 'array', 'mixed' => 'array', 'custom' => 'array'];
}

class RFAPageResource extends Resource
{
    public static function model(): string
    {
        return RFAPageModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->nullable(),
            Repeater::make('sections', 'Sections')->asJson()->repeatables([RFASection::make()]),
            Repeater::make('keyed', 'Keyed')->asJson()->uniqueField('uuid')->repeatables([RFASection::make()]),
            Repeater::make('menus', 'Menus')->asJson()->repeatables([RFAMenu::make()]),
            Repeater::make('mixed', 'Mixed')->asJson()->repeatables([RFASection::make(), RFALink::make()]),
            Repeater::make('custom', 'Custom')->repeatables([RFASection::make()])
                ->fillUsing(function (Model $model, mixed $value, string $attribute): void {
                    $model->setAttribute($attribute, $value);
                }),
        ];
    }
}

class RFATaskModel extends Model
{
    protected $table = 'rfa_tasks';

    protected $guarded = [];

    public $timestamps = false;
}

class RFATask extends Repeatable
{
    public static ?string $model = RFATaskModel::class;

    public function fields(Request $request): array
    {
        return rfaRowFields();
    }
}

class RFATodoModel extends Model
{
    protected $table = 'rfa_todos';

    protected $guarded = [];

    public $timestamps = false;
}

class RFATodo extends Repeatable
{
    public static ?string $model = RFATodoModel::class;

    public function fields(Request $request): array
    {
        return [Text::make('name', 'Name')->required(), Text::make('code', 'Code')->readonly()];
    }
}

class RFABlockModel extends Model
{
    protected $table = 'rfa_blocks';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['payload' => 'array'];
}

class RFABlock extends Repeatable
{
    public static ?string $model = RFABlockModel::class;

    public function fields(Request $request): array
    {
        return rfaRowFields();
    }
}

class RFAProjectModel extends Model
{
    protected $table = 'rfa_projects';

    protected $guarded = [];

    public $timestamps = false;

    public function tasks(): EloquentHasMany
    {
        return $this->hasMany(RFATaskModel::class, 'project_id');
    }

    public function todos(): EloquentHasMany
    {
        return $this->hasMany(RFATodoModel::class, 'project_id');
    }

    public function blocks(): EloquentHasMany
    {
        return $this->hasMany(RFABlockModel::class, 'project_id');
    }
}

class RFAProjectResource extends Resource
{
    public static function model(): string
    {
        return RFAProjectModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->nullable(),
            Repeater::make('tasks', 'Tasks')->asHasMany()->uniqueField('uuid')->repeatables([RFATask::make()]),
            // No unique field: a row is matched by its child's primary key.
            Repeater::make('todos', 'Todos')->asHasMany()->repeatables([RFATodo::make()]),
            Repeater::make('blocks', 'Blocks')->asPolymorphic()->uniqueField('uuid')->repeatables([RFABlock::make()]),
        ];
    }
}

class RFAPlacement extends Pivot
{
    protected $casts = ['steps' => 'array'];
}

class RFAClientModel extends Model
{
    protected $table = 'rfa_clients';

    protected $guarded = [];

    public $timestamps = false;

    public function pages(): EloquentBelongsToMany
    {
        return $this->belongsToMany(RFAPageModel::class, 'rfa_client_page', 'client_id', 'page_id')
            ->using(RFAPlacement::class)
            ->withPivot(['steps']);
    }

    public function sites(): EloquentHasMany
    {
        return $this->hasMany(RFAPageModel::class, 'client_id');
    }
}

class RFAClientResource extends Resource
{
    public static function model(): string
    {
        return RFAClientModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->nullable(),
            HasMany::make('Sites', 'sites')->relatedResource('r-f-a-page-models'),
            BelongsToMany::make('Pages', 'pages')
                ->relatedResource('r-f-a-page-models')
                ->fields(fn () => [
                    Repeater::make('steps', 'Steps')->asJson()->repeatables([RFASection::make()]),
                ]),
        ];
    }
}

const RFA_TABLES = ['rfa_client_page', 'rfa_clients', 'rfa_blocks', 'rfa_todos', 'rfa_tasks', 'rfa_projects', 'rfa_pages'];

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (RFA_TABLES as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('rfa_pages', function ($table) {
        $table->id();
        $table->unsignedBigInteger('client_id')->nullable();
        $table->string('name')->nullable();
        foreach (['sections', 'keyed', 'menus', 'mixed', 'custom'] as $column) {
            $table->json($column)->nullable();
        }
    });
    Schema::create('rfa_projects', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('rfa_tasks', function ($table) {
        $table->id();
        $table->unsignedBigInteger('project_id');
        $table->string('uuid')->nullable();
        foreach (['name', 'code', 'status', 'secret', 'tier', 'slug'] as $column) {
            $table->string($column)->nullable();
        }
    });
    Schema::create('rfa_todos', function ($table) {
        $table->id();
        $table->unsignedBigInteger('project_id');
        $table->string('name')->nullable();
        $table->string('code')->nullable();
    });
    Schema::create('rfa_blocks', function ($table) {
        $table->id();
        $table->unsignedBigInteger('project_id');
        $table->string('uuid')->nullable();
        $table->string('type');
        $table->json('payload')->nullable();
    });
    Schema::create('rfa_clients', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('rfa_client_page', function ($table) {
        $table->unsignedBigInteger('client_id');
        $table->unsignedBigInteger('page_id');
        $table->json('steps')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(RFAPageResource::class);
    $registry->register(RFAProjectResource::class);
    $registry->register(RFAClientResource::class);
});

afterEach(function () {
    foreach (RFA_TABLES as $table) {
        Schema::dropIfExists($table);
    }
});

function rfaSection(array $fields, array $extra = []): array
{
    return ['type' => 'r-f-a-section', 'fields' => $fields] + $extra;
}

/** @return list<string> */
function rfaErrorFields($response): array
{
    return collect($response->json('errors'))->pluck('field')->all();
}

/** The attributes of the fields a schema lists for the first row type of a Repeater. */
function rfaSchemaRowAttributes($response, string $repeater): array
{
    $field = collect($response->json('data.fieldsForUpdate'))->firstWhere('attribute', $repeater);

    return array_column($field['repeatables'][0]['fields'], 'attribute');
}

// ---------------------------------------------------------------------------
// JSON storage
// ---------------------------------------------------------------------------

it('keeps the stored value of every field a stored JSON row cannot write', function () {
    $page = RFAPageModel::create(['sections' => [[
        'id' => 'a',
        'type' => 'r-f-a-section',
        'fields' => ['name' => 'Hero', 'code' => 'C-1', 'status' => 'live', 'secret' => 's3cr3t', 'tier' => 'gold', 'slug' => 'hero'],
    ]]]);

    $this->putJson("/martis/api/resources/r-f-a-page-models/{$page->id}", [
        'sections' => [rfaSection(['name' => 'Hero 2', 'slug' => 'renamed'] + RFA_FORGED, ['id' => 'a'])],
    ])->assertOk();

    $row = $page->fresh()->sections[0];
    expect($row['id'])->toBe('a')
        ->and($row['fields'])->toEqual([
            'name' => 'Hero 2', 'code' => 'C-1', 'status' => 'live', 'secret' => 's3cr3t', 'tier' => 'gold', 'slug' => 'hero',
        ]);
});

it('gives a new JSON row the default of a field it cannot write, and nothing when there is none', function () {
    $this->postJson('/martis/api/resources/r-f-a-page-models', [
        'sections' => [rfaSection(['name' => 'New', 'slug' => 'new'] + RFA_FORGED)],
    ])->assertCreated();

    expect(RFAPageModel::sole()->sections[0]['fields'])->toEqual([
        'name' => 'New', 'status' => 'draft', 'tier' => 'basic', 'slug' => 'new',
    ]);
});

it('treats a row that carries the id of a stored row of another type as a new row', function () {
    $page = RFAPageModel::create(['mixed' => [['id' => 'a', 'type' => 'r-f-a-link', 'fields' => ['url' => '/', 'code' => 'C-1']]]]);

    // The section that takes the link's id does not inherit the link's code.
    $this->putJson("/martis/api/resources/r-f-a-page-models/{$page->id}", [
        'mixed' => [rfaSection(['name' => 'Hero', 'code' => 'forged'], ['id' => 'a'])],
    ])->assertOk();

    expect($page->fresh()->mixed[0]['fields'])->toEqual(['name' => 'Hero', 'status' => 'draft', 'tier' => 'basic']);
});

it('does not validate a row field the user cannot see', function () {
    // `secret` is required, but hidden from this user: the form never shows it.
    $this->postJson('/martis/api/resources/r-f-a-page-models', [
        'sections' => [rfaSection(['name' => 'New'])],
    ])->assertCreated();

    expect(RFAPageModel::sole()->sections[0]['fields'])->not->toHaveKey('secret');
});

it('validates an immutable row field on a new row only, since a stored row keeps its value', function () {
    $page = RFAPageModel::create(['sections' => [['id' => 'a', 'type' => 'r-f-a-section', 'fields' => ['name' => 'Hero', 'slug' => 'legacy-too-long']]]]);
    $uri = "/martis/api/resources/r-f-a-page-models/{$page->id}";

    // The stored row sends back a slug longer than max:8: kept, not validated.
    $this->putJson($uri, ['sections' => [rfaSection(['name' => 'Hero', 'slug' => 'changed-too-long'], ['id' => 'a'])]])->assertOk();
    expect($page->fresh()->sections[0]['fields']['slug'])->toBe('legacy-too-long');

    $response = $this->putJson($uri, ['sections' => [
        rfaSection(['name' => 'Hero', 'slug' => 'legacy-too-long'], ['id' => 'a']),
        rfaSection(['name' => 'New', 'slug' => 'far-too-long']),
    ]]);

    $response->assertStatus(422);
    expect(rfaErrorFields($response))->toBe(['sections.1.fields.slug']);
});

it('leaves the row fields the user cannot see out of the schema and out of the row values', function () {
    $page = RFAPageModel::create(['sections' => [[
        'id' => 'a', 'type' => 'r-f-a-section', 'fields' => ['name' => 'Hero', 'code' => 'C-1', 'secret' => 's3cr3t', 'tier' => 'gold'],
    ]]]);

    $schema = $this->getJson('/martis/api/resources/r-f-a-page-models/schema')->assertOk();
    expect(rfaSchemaRowAttributes($schema, 'sections'))->toBe(['name', 'code', 'status', 'total', 'slug']);

    foreach (['update', 'detail'] as $context) {
        $row = $this->getJson("/martis/api/resources/r-f-a-page-models/{$page->id}?context={$context}")->assertOk()->json('data.sections.0');
        expect($row['fields'])->toBe(['name' => 'Hero', 'code' => 'C-1']);
    }
});

it('matches a JSON row by the unique field its id carries and keeps that id', function () {
    $page = RFAPageModel::create(['keyed' => [[
        'id' => 'x', 'uuid' => 'u-1', 'type' => 'r-f-a-section', 'fields' => ['name' => 'Hero', 'code' => 'C-1'],
    ]]]);
    $uri = "/martis/api/resources/r-f-a-page-models/{$page->id}";

    $id = $this->getJson("{$uri}?context=update")->json('data.keyed.0.id');
    expect($id)->toBe('u-1');

    $this->putJson($uri, ['keyed' => [rfaSection(['name' => 'Hero 2', 'code' => 'forged'], ['id' => $id])]])->assertOk();

    $row = $page->fresh()->keyed[0];
    expect($row['uuid'])->toBe('u-1')
        ->and($row['fields'])->toEqual(['name' => 'Hero 2', 'code' => 'C-1'])
        ->and($this->getJson("{$uri}?context=update")->json('data.keyed.0.id'))->toBe('u-1');
});

it('gives a stored JSON row without an id a stable id, so the form can send it back', function () {
    $page = RFAPageModel::create(['sections' => [
        ['type' => 'r-f-a-section', 'fields' => ['name' => 'First', 'code' => 'C-1']],
        ['type' => 'r-f-a-section', 'fields' => ['name' => 'Second', 'code' => 'C-2']],
    ]]);
    $uri = "/martis/api/resources/r-f-a-page-models/{$page->id}";

    $rows = $this->getJson("{$uri}?context=update")->json('data.sections');
    expect($rows[0]['id'])->toBeString()->not->toBe('')
        ->and($rows[1]['id'])->toBeString()->not->toBe($rows[0]['id'])
        ->and($this->getJson("{$uri}?context=update")->json('data.sections.0.id'))->toBe($rows[0]['id']);

    // The form swaps the two rows and forges their codes.
    $this->putJson($uri, ['sections' => [
        rfaSection(['name' => 'Second', 'code' => 'forged'], ['id' => $rows[1]['id']]),
        rfaSection(['name' => 'First', 'code' => 'forged'], ['id' => $rows[0]['id']]),
    ]])->assertOk();

    $stored = $page->fresh()->sections;
    expect(array_column($stored, 'id'))->toBe([$rows[1]['id'], $rows[0]['id']])
        ->and(array_column(array_column($stored, 'fields'), 'code'))->toBe(['C-2', 'C-1']);
});

it('protects and hides the fields of a Repeater inside a row', function () {
    $page = RFAPageModel::create(['menus' => [[
        'id' => 'm',
        'type' => 'r-f-a-menu',
        'fields' => ['label' => 'Main', 'links' => [
            ['id' => 'l', 'type' => 'r-f-a-link', 'fields' => ['url' => '/', 'code' => 'C-1', 'secret' => 's3cr3t']],
        ]],
    ]]]);
    $uri = "/martis/api/resources/r-f-a-page-models/{$page->id}";

    $link = $this->getJson("{$uri}?context=update")->json('data.menus.0.fields.links.0');
    expect($link)->toBe(['id' => 'l', 'type' => 'r-f-a-link', 'fields' => ['url' => '/', 'code' => 'C-1']]);

    $this->putJson($uri, ['menus' => [[
        'id' => 'm',
        'type' => 'r-f-a-menu',
        'fields' => ['label' => 'Main', 'links' => [
            ['id' => 'l', 'type' => 'r-f-a-link', 'fields' => ['url' => '/home', 'code' => 'forged', 'secret' => 'forged']],
            ['id' => 'n', 'type' => 'r-f-a-link', 'fields' => ['url' => '/new', 'code' => 'forged', 'secret' => 'forged']],
        ]],
    ]]])->assertOk();

    $links = $page->fresh()->menus[0]['fields']['links'];
    expect($links[0]['fields'])->toEqual(['url' => '/home', 'code' => 'C-1', 'secret' => 's3cr3t'])
        ->and($links[1]['fields'])->toEqual(['url' => '/new']);
});

it('keeps the stored values of a row on the HasMany inline update, and validates an immutable field of a new row only', function () {
    $client = RFAClientModel::create(['name' => 'Acme']);
    $page = RFAPageModel::create(['client_id' => $client->id, 'sections' => [
        ['id' => 'a', 'type' => 'r-f-a-section', 'fields' => ['name' => 'Hero', 'code' => 'C-1', 'slug' => 'legacy-too-long']],
    ]]);
    $uri = "/martis/api/resources/r-f-a-client-models/{$client->id}/has-many/sites/{$page->id}";

    $this->putJson($uri, [
        'sections' => [rfaSection(['name' => 'Hero 2', 'code' => 'forged', 'slug' => 'changed-too-long'], ['id' => 'a'])],
    ])->assertOk();
    expect($page->fresh()->sections[0]['fields'])->toEqual(['name' => 'Hero 2', 'code' => 'C-1', 'slug' => 'legacy-too-long']);

    $response = $this->putJson($uri, ['sections' => [
        rfaSection(['name' => 'Hero 2', 'slug' => 'legacy-too-long'], ['id' => 'a']),
        rfaSection(['name' => 'New', 'slug' => 'far-too-long']),
    ]]);
    $response->assertStatus(422);
    expect(rfaErrorFields($response))->toBe(['sections.1.fields.slug']);
});

it('hands a fillUsing() callback the rows with the stored values of the fields they cannot write', function () {
    $page = RFAPageModel::create(['custom' => [['id' => 'a', 'type' => 'r-f-a-section', 'fields' => ['name' => 'Hero', 'code' => 'C-1']]]]);

    $this->putJson("/martis/api/resources/r-f-a-page-models/{$page->id}", [
        'custom' => [rfaSection(['name' => 'Hero 2'] + RFA_FORGED, ['id' => 'a'])],
    ])->assertOk();

    expect($page->fresh()->custom[0]['fields'])->toEqual(['name' => 'Hero 2', 'code' => 'C-1']);
});

// ---------------------------------------------------------------------------
// HasMany storage
// ---------------------------------------------------------------------------

it('keeps the stored column of every field a stored HasMany row cannot write, and updates the child in place', function () {
    $project = RFAProjectModel::create(['name' => 'Site']);
    $task = $project->tasks()->create([
        'uuid' => 'u-1', 'name' => 'Design', 'code' => 'C-1', 'status' => 'live', 'secret' => 's3cr3t', 'tier' => 'gold', 'slug' => 'design',
    ]);

    $this->putJson("/martis/api/resources/r-f-a-project-models/{$project->id}", [
        'tasks' => [['id' => 'u-1', 'type' => 'r-f-a-task', 'fields' => ['name' => 'Design 2', 'slug' => 'renamed'] + RFA_FORGED]],
    ])->assertOk();

    $fresh = RFATaskModel::sole();
    expect($fresh->id)->toBe($task->id)
        ->and($fresh->only(['uuid', 'name', 'code', 'status', 'secret', 'tier', 'slug']))->toBe([
            'uuid' => 'u-1', 'name' => 'Design 2', 'code' => 'C-1', 'status' => 'live', 'secret' => 's3cr3t', 'tier' => 'gold', 'slug' => 'design',
        ]);
});

it('gives a new HasMany row the default of a field it cannot write, and nothing when there is none', function () {
    $this->postJson('/martis/api/resources/r-f-a-project-models', [
        'name' => 'Site',
        'tasks' => [['type' => 'r-f-a-task', 'fields' => ['name' => 'Build', 'slug' => 'build'] + RFA_FORGED]],
    ])->assertCreated();

    $task = RFATaskModel::sole();
    expect($task->only(['name', 'code', 'status', 'secret', 'tier', 'slug']))->toBe([
        'name' => 'Build', 'code' => null, 'status' => 'draft', 'secret' => null, 'tier' => 'basic', 'slug' => 'build',
    ])->and($task->uuid)->toBeString()->not->toBe('');
});

it('reads only the HasMany row values the user can see', function () {
    $project = RFAProjectModel::create(['name' => 'Site']);
    $project->tasks()->create(['uuid' => 'u-1', 'name' => 'Design', 'code' => 'C-1', 'secret' => 's3cr3t', 'tier' => 'gold']);

    $row = $this->getJson("/martis/api/resources/r-f-a-project-models/{$project->id}?context=update")->assertOk()->json('data.tasks.0');

    expect($row['id'])->toBe('u-1')
        ->and($row['fields'])->toBe(['name' => 'Design', 'code' => 'C-1', 'status' => null, 'total' => 'computed', 'slug' => null]);
});

it('matches a HasMany row by its primary key when the Repeater has no unique field', function () {
    $project = RFAProjectModel::create(['name' => 'Site']);
    $todo = $project->todos()->create(['name' => 'Call', 'code' => 'C-1']);
    $uri = "/martis/api/resources/r-f-a-project-models/{$project->id}";

    $id = $this->getJson("{$uri}?context=update")->json('data.todos.0.id');
    expect($id)->toBe($todo->id);

    $this->putJson($uri, ['todos' => [
        ['id' => $id, 'type' => 'r-f-a-todo', 'fields' => ['name' => 'Call back', 'code' => 'forged']],
        ['id' => 'b9f1c7de-3c55-4d7e-8a4e-0d5c2d0f6a11', 'type' => 'r-f-a-todo', 'fields' => ['name' => 'Email', 'code' => 'forged']],
    ]])->assertOk();

    $todos = RFATodoModel::query()->orderBy('id')->get();
    expect($todos)->toHaveCount(2)
        ->and($todos[0]->only(['id', 'name', 'code']))->toBe(['id' => $todo->id, 'name' => 'Call back', 'code' => 'C-1'])
        ->and($todos[1]->only(['name', 'code']))->toBe(['name' => 'Email', 'code' => null]);
});

it('deletes the HasMany children whose rows the update no longer sends', function () {
    $project = RFAProjectModel::create(['name' => 'Site']);
    $project->tasks()->create(['uuid' => 'u-1', 'name' => 'Keep']);
    $project->tasks()->create(['uuid' => 'u-2', 'name' => 'Drop']);
    $project->tasks()->create(['name' => 'No uuid']);

    $this->putJson("/martis/api/resources/r-f-a-project-models/{$project->id}", [
        'tasks' => [['id' => 'u-1', 'type' => 'r-f-a-task', 'fields' => ['name' => 'Keep']]],
    ])->assertOk();

    expect(RFATaskModel::query()->pluck('uuid')->all())->toBe(['u-1']);
});

// ---------------------------------------------------------------------------
// Polymorphic storage
// ---------------------------------------------------------------------------

it('keeps the stored payload value of every field a stored polymorphic row cannot write', function () {
    $project = RFAProjectModel::create(['name' => 'Site']);
    $block = $project->blocks()->create(['uuid' => 'u-1', 'type' => 'r-f-a-block', 'payload' => [
        'name' => 'Hero', 'code' => 'C-1', 'status' => 'live', 'secret' => 's3cr3t', 'tier' => 'gold', 'slug' => 'hero',
    ]]);

    $this->putJson("/martis/api/resources/r-f-a-project-models/{$project->id}", [
        'blocks' => [['id' => 'u-1', 'type' => 'r-f-a-block', 'fields' => ['name' => 'Hero 2', 'slug' => 'renamed'] + RFA_FORGED]],
    ])->assertOk();

    $fresh = RFABlockModel::sole();
    expect($fresh->id)->toBe($block->id)
        ->and($fresh->payload)->toEqual([
            'name' => 'Hero 2', 'code' => 'C-1', 'status' => 'live', 'secret' => 's3cr3t', 'tier' => 'gold', 'slug' => 'hero',
        ]);
});

it('gives a new polymorphic row the default of a field it cannot write, and nothing when there is none', function () {
    $this->postJson('/martis/api/resources/r-f-a-project-models', [
        'name' => 'Site',
        'blocks' => [['type' => 'r-f-a-block', 'fields' => ['name' => 'Hero', 'slug' => 'hero'] + RFA_FORGED]],
    ])->assertCreated();

    expect(RFABlockModel::sole()->payload)->toEqual(['name' => 'Hero', 'status' => 'draft', 'tier' => 'basic', 'slug' => 'hero']);
});

it('reads only the polymorphic row values the user can see', function () {
    $project = RFAProjectModel::create(['name' => 'Site']);
    $project->blocks()->create(['uuid' => 'u-1', 'type' => 'r-f-a-block', 'payload' => ['name' => 'Hero', 'secret' => 's3cr3t', 'tier' => 'gold']]);

    $row = $this->getJson("/martis/api/resources/r-f-a-project-models/{$project->id}?context=update")->assertOk()->json('data.blocks.0');

    expect($row['fields'])->toBe(['name' => 'Hero']);
});

// ---------------------------------------------------------------------------
// Pivot Repeater
// ---------------------------------------------------------------------------

it('keeps the stored values of the fields a pivot Repeater row cannot write on pivot update', function () {
    $client = RFAClientModel::create(['name' => 'Acme']);
    $page = RFAPageModel::create(['name' => 'Home']);
    $client->pages()->attach($page->id, ['steps' => [
        ['id' => 'a', 'type' => 'r-f-a-section', 'fields' => ['name' => 'Intro', 'code' => 'C-1', 'secret' => 's3cr3t', 'slug' => 'intro']],
    ]]);

    $response = $this->putJson("/martis/api/resources/r-f-a-client-models/{$client->id}/belongs-to-many/pages/{$page->id}/pivot", [
        'steps' => [rfaSection(['name' => 'Intro 2', 'slug' => 'renamed'] + RFA_FORGED, ['id' => 'a'])],
    ]);

    $response->assertOk();
    expect($client->pages()->sole()->pivot->steps[0]['fields'])->toEqual([
        'name' => 'Intro 2', 'code' => 'C-1', 'secret' => 's3cr3t', 'slug' => 'intro',
    ])->and($response->json('data.pivot.steps.0.fields'))->toEqual(['name' => 'Intro 2', 'code' => 'C-1', 'slug' => 'intro']);
});

it('attaches a pivot Repeater row with the defaults of the fields it cannot write', function () {
    $client = RFAClientModel::create(['name' => 'Acme']);
    $page = RFAPageModel::create(['name' => 'Home']);

    $this->postJson("/martis/api/resources/r-f-a-client-models/{$client->id}/belongs-to-many/pages/attach", [
        'related_id' => $page->id,
        'steps' => [rfaSection(['name' => 'Intro', 'slug' => 'intro'] + RFA_FORGED)],
    ])->assertCreated();

    expect($client->pages()->sole()->pivot->steps[0]['fields'])->toEqual([
        'name' => 'Intro', 'status' => 'draft', 'tier' => 'basic', 'slug' => 'intro',
    ]);
});

it('lists the pivot Repeater rows without the fields the user cannot see', function () {
    $client = RFAClientModel::create(['name' => 'Acme']);
    $page = RFAPageModel::create(['name' => 'Home']);
    $client->pages()->attach($page->id, ['steps' => [
        ['id' => 'a', 'type' => 'r-f-a-section', 'fields' => ['name' => 'Intro', 'secret' => 's3cr3t', 'tier' => 'gold']],
    ]]);

    $steps = $this->getJson("/martis/api/resources/r-f-a-client-models/{$client->id}/belongs-to-many/pages")
        ->assertOk()
        ->json('data.0._pivot.steps');

    expect($steps)->toBe([['id' => 'a', 'type' => 'r-f-a-section', 'fields' => ['name' => 'Intro']]]);
});

// ---------------------------------------------------------------------------
// The field itself
// ---------------------------------------------------------------------------

it('serialises only the row fields the user can see, in the row types and in the row templates', function () {
    $field = Repeater::make('sections')
        ->repeatables([RFASection::make()])
        ->rowTemplate('Preset', 'r-f-a-section', ['name' => 'Preset', 'secret' => 'template secret', 'tier' => 'gold']);

    $payload = $field->toArray();

    expect(array_column($payload['repeatables'][0]['fields'], 'attribute'))->toBe(['name', 'code', 'status', 'total', 'slug'])
        ->and($payload['rowTemplates'][0]['fields'])->toBe(['name' => 'Preset'])
        ->and(array_column(RFASection::make()->toArray(Request::create('/'))['fields'], 'attribute'))->toBe(['name', 'code', 'status', 'total', 'slug']);
});

it('serialises the default rows of a Repeater without the fields the user cannot see', function () {
    $field = Repeater::make('sections')
        ->repeatables([RFASection::make()])
        ->default([['id' => 'a', 'type' => 'r-f-a-section', 'fields' => ['name' => 'Preset', 'secret' => 'default secret']]]);

    expect($field->toArray()['defaultValue'])->toBe([['id' => 'a', 'type' => 'r-f-a-section', 'fields' => ['name' => 'Preset']]]);
});

it('builds no rule for a row field the row cannot write', function () {
    $field = Repeater::make('sections')->repeatables([RFASection::make()]);
    $page = new RFAPageModel(['sections' => [['id' => 'a', 'type' => 'r-f-a-section', 'fields' => ['name' => 'Hero']]]]);
    $data = ['sections' => [rfaSection([], ['id' => 'a']), rfaSection([])]];

    // Without the record every row is new; with it, row `a` is stored and
    // keeps its immutable slug.
    expect(array_keys($field->buildRowValidation($data, 'update')['rules']))->toBe([
        'sections.0.fields.name', 'sections.0.fields.slug', 'sections.1.fields.name', 'sections.1.fields.slug',
    ])->and(array_keys($field->buildRowValidation($data, 'update', $page)['rules']))->toBe([
        'sections.0.fields.name', 'sections.1.fields.name', 'sections.1.fields.slug',
    ]);
});
