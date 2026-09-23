<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\Image;
use Martis\Fields\Number;
use Martis\Fields\Repeatable;
use Martis\Fields\Repeater;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// The fields inside a Repeater's rows are validated on the server (v1.38.0).
//
// No endpoint validated them: the rules of a field inside a Repeatable
// (`required()`, `rules()`, `creationRules()` / `updateRules()`) never
// reached the validator, so a row with a missing or invalid value was stored
// as sent. Every endpoint that writes a Repeater now validates each row it
// receives with the fields of the row's Repeatable, under
// `{attribute}.{index}.fields.{field}` and named by the field's label, and a
// row whose type names no Repeatable fails on its `type`.
// ===========================================================================

class RRVSection extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            Text::make('key', 'Key')->required(),
            Text::make('title', 'Title')->nullable()->rules(['max:10']),
            // The row form cannot change a readonly field: never validated.
            Text::make('note', 'Note')->readonly()->rules(['required']),
        ];
    }
}

class RRVHero extends Repeatable
{
    public function fields(Request $request): array
    {
        return [Text::make('headline', 'Headline')->required()];
    }
}

class RRVStats extends Repeatable
{
    public function fields(Request $request): array
    {
        return [Number::make('value', 'Value')->rules(['required', 'integer'])];
    }
}

class RRVCode extends Repeatable
{
    public function fields(Request $request): array
    {
        return [Text::make('code', 'Code')->creationRules(['required'])->updateRules(['max:3'])];
    }
}

class RRVLink extends Repeatable
{
    public function fields(Request $request): array
    {
        return [Text::make('url', 'URL')->required()];
    }
}

class RRVMenu extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            Text::make('label', 'Label')->required(),
            Repeater::make('links', 'Links')->repeatables([RRVLink::make()]),
        ];
    }
}

class RRVGalleryItem extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            Text::make('caption', 'Caption')->required(),
            Image::make('photo', 'Photo')->rules(['required']),
        ];
    }
}

class RRVPageModel extends Model
{
    protected $table = 'rrv_pages';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'sections' => 'array',
        'blocks' => 'array',
        'codes' => 'array',
        'menus' => 'array',
        'locked' => 'array',
        'custom' => 'array',
        'limited' => 'array',
        'gallery' => 'array',
    ];
}

class RRVPageResource extends Resource
{
    public static function model(): string
    {
        return RRVPageModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->nullable(),
            Image::make('cover')->disk('rrv_disk')->storagePath('covers')->nullable(),
            Repeater::make('sections', 'Sections')->asJson()->repeatables([RRVSection::make()]),
            Repeater::make('blocks', 'Blocks')->asJson()->repeatables([RRVHero::make(), RRVStats::make()]),
            Repeater::make('codes', 'Codes')->asJson()->repeatables([RRVCode::make()]),
            Repeater::make('menus', 'Menus')->asJson()->repeatables([RRVMenu::make()]),
            Repeater::make('locked', 'Locked')->asJson()->readonly()->repeatables([RRVSection::make()]),
            Repeater::make('custom', 'Custom')->repeatables([RRVSection::make()])
                ->fillUsing(function (Model $model, mixed $value, string $attribute): void {
                    $model->setAttribute($attribute, $value);
                }),
            Repeater::make('limited', 'Limited')->asJson()->minRows(1)->maxRows(2)
                ->rules(['array', 'min:1', 'max:2'])
                ->repeatables([RRVHero::make()]),
            Repeater::make('gallery', 'Gallery')->asJson()->repeatables([RRVGalleryItem::make()]),
        ];
    }
}

class RRVTaskModel extends Model
{
    protected $table = 'rrv_tasks';

    protected $guarded = [];

    public $timestamps = false;
}

class RRVTask extends Repeatable
{
    public static ?string $model = RRVTaskModel::class;

    public function fields(Request $request): array
    {
        return [Text::make('name', 'Name')->required()];
    }
}

class RRVProjectModel extends Model
{
    protected $table = 'rrv_projects';

    protected $guarded = [];

    public $timestamps = false;

    public function tasks(): EloquentHasMany
    {
        return $this->hasMany(RRVTaskModel::class, 'project_id');
    }
}

class RRVProjectResource extends Resource
{
    public static function model(): string
    {
        return RRVProjectModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->nullable()->unique(['rrv_projects', 'name'], 'That project name is taken.'),
            Repeater::make('tasks', 'Tasks')->asHasMany()->uniqueField('uuid')->repeatables([RRVTask::make()]),
        ];
    }
}

class RRVPlacement extends Pivot
{
    protected $casts = ['steps' => 'array'];
}

class RRVClientModel extends Model
{
    protected $table = 'rrv_clients';

    protected $guarded = [];

    public $timestamps = false;

    public function projects(): EloquentHasMany
    {
        return $this->hasMany(RRVProjectModel::class, 'client_id');
    }

    public function pages(): EloquentBelongsToMany
    {
        return $this->belongsToMany(RRVPageModel::class, 'rrv_client_page', 'client_id', 'page_id')
            ->using(RRVPlacement::class)
            ->withPivot(['steps']);
    }
}

class RRVImportAction extends Action
{
    public ?string $name = 'Import';

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('Imported.');
    }

    public function fields(Request $request): array
    {
        return [Repeater::make('rows', 'Rows')->repeatables([RRVSection::make()])];
    }
}

class RRVClientResource extends Resource
{
    public static function model(): string
    {
        return RRVClientModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->nullable(),
            HasMany::make('Projects', 'projects')->relatedResource('r-r-v-project-models'),
            BelongsToMany::make('Pages', 'pages')
                ->relatedResource('r-r-v-page-models')
                ->fields(fn () => [
                    Repeater::make('steps', 'Steps')->asJson()->repeatables([RRVSection::make()]),
                ]),
        ];
    }

    public function actions(Request $request): array
    {
        return [RRVImportAction::make()->standalone()];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    Storage::fake('rrv_disk');

    foreach (['rrv_client_page', 'rrv_tasks', 'rrv_projects', 'rrv_clients', 'rrv_pages'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('rrv_pages', function ($table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('cover')->nullable();
        foreach (['sections', 'blocks', 'codes', 'menus', 'locked', 'custom', 'limited', 'gallery'] as $column) {
            $table->json($column)->nullable();
        }
    });
    Schema::create('rrv_clients', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('rrv_projects', function ($table) {
        $table->id();
        $table->unsignedBigInteger('client_id')->nullable();
        $table->string('name')->nullable();
    });
    Schema::create('rrv_tasks', function ($table) {
        $table->id();
        $table->unsignedBigInteger('project_id');
        $table->string('uuid')->nullable();
        $table->string('name')->nullable();
    });
    Schema::create('rrv_client_page', function ($table) {
        $table->unsignedBigInteger('client_id');
        $table->unsignedBigInteger('page_id');
        $table->json('steps')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(RRVPageResource::class);
    $registry->register(RRVProjectResource::class);
    $registry->register(RRVClientResource::class);
});

afterEach(function () {
    foreach (['rrv_client_page', 'rrv_tasks', 'rrv_projects', 'rrv_clients', 'rrv_pages'] as $table) {
        Schema::dropIfExists($table);
    }
});

/** @return list<string> */
function rrvErrorFields($response): array
{
    return collect($response->json('errors'))->pluck('field')->all();
}

/** @return array<string, string> */
function rrvErrorMessages($response): array
{
    return collect($response->json('errors'))->pluck('message', 'field')->all();
}

function rrvSection(array $fields, array $extra = []): array
{
    return ['type' => 'r-r-v-section', 'fields' => $fields] + $extra;
}

// ---------------------------------------------------------------------------
// Resource create and update (JSON)
// ---------------------------------------------------------------------------

it('rejects a row whose required field is missing on create, under the row path and named by the field label', function () {
    $response = $this->postJson('/martis/api/resources/r-r-v-page-models', [
        'sections' => [
            rrvSection(['key' => 'hero']),
            rrvSection(['key' => null, 'title' => 'Stats']),
        ],
    ]);

    $response->assertStatus(422);
    expect(rrvErrorFields($response))->toBe(['sections.1.fields.key'])
        ->and(rrvErrorMessages($response))->toBe(['sections.1.fields.key' => 'The Key field is required.'])
        ->and(RRVPageModel::count())->toBe(0);
});

it('stores valid rows on create', function () {
    $response = $this->postJson('/martis/api/resources/r-r-v-page-models', [
        'sections' => [rrvSection(['key' => 'hero', 'title' => 'Hero'])],
    ]);

    $response->assertStatus(201);
    expect(RRVPageModel::sole()->sections[0]['fields'])->toBe(['key' => 'hero', 'title' => 'Hero']);
});

it('applies every rule a row field declares, not only required', function () {
    $response = $this->postJson('/martis/api/resources/r-r-v-page-models', [
        'sections' => [rrvSection(['key' => 'hero', 'title' => 'A title longer than ten'])],
    ]);

    $response->assertStatus(422);
    expect(rrvErrorMessages($response))->toBe([
        'sections.0.fields.title' => 'The Title field must not be greater than 10 characters.',
    ]);
});

it('keeps a row field required on update, for a stored row and for a new one', function () {
    $stored = [['id' => 'a', 'type' => 'r-r-v-section', 'fields' => ['key' => 'hero']]];
    $page = RRVPageModel::create(['sections' => $stored]);

    $response = $this->putJson("/martis/api/resources/r-r-v-page-models/{$page->id}", [
        'sections' => [
            rrvSection(['key' => ''], ['id' => 'a']),
            rrvSection(['title' => 'New']),
        ],
    ]);

    $response->assertStatus(422);
    expect(rrvErrorFields($response))->toBe(['sections.0.fields.key', 'sections.1.fields.key'])
        ->and($page->fresh()->sections)->toBe($stored);
});

it('stores valid rows on update', function () {
    $page = RRVPageModel::create(['sections' => [['id' => 'a', 'type' => 'r-r-v-section', 'fields' => ['key' => 'hero']]]]);

    $response = $this->putJson("/martis/api/resources/r-r-v-page-models/{$page->id}", [
        'sections' => [
            rrvSection(['key' => 'hero', 'title' => 'Hero'], ['id' => 'a']),
            rrvSection(['key' => 'stats']),
        ],
    ]);

    $response->assertStatus(200);
    $sections = $page->fresh()->sections;
    expect($sections)->toHaveCount(2)
        ->and($sections[0]['fields'])->toBe(['key' => 'hero', 'title' => 'Hero'])
        ->and($sections[1]['fields'])->toBe(['key' => 'stats']);
});

it('ignores a file field inside a row, which a row cannot upload, and keeps the stored path it sends back', function () {
    $stored = [['id' => 'a', 'type' => 'r-r-v-gallery-item', 'fields' => ['caption' => 'Front', 'photo' => 'photos/front.png']]];
    $page = RRVPageModel::create(['gallery' => $stored]);

    $response = $this->putJson("/martis/api/resources/r-r-v-page-models/{$page->id}", ['gallery' => $stored]);

    $response->assertStatus(200);
    expect($page->fresh()->gallery[0]['fields'])->toBe(['caption' => 'Front', 'photo' => 'photos/front.png']);

    // The other fields of the row are still validated.
    $missing = $this->putJson("/martis/api/resources/r-r-v-page-models/{$page->id}", [
        'gallery' => [['id' => 'a', 'type' => 'r-r-v-gallery-item', 'fields' => ['photo' => 'photos/front.png']]],
    ]);
    $missing->assertStatus(422);
    expect(rrvErrorFields($missing))->toBe(['gallery.0.fields.caption']);
});

it('ignores a readonly field inside a row', function () {
    // `note` declares `required` but is readonly: a row without it is valid.
    $response = $this->postJson('/martis/api/resources/r-r-v-page-models', [
        'sections' => [rrvSection(['key' => 'hero'])],
    ]);

    $response->assertStatus(201);
});

// ---------------------------------------------------------------------------
// Row types
// ---------------------------------------------------------------------------

it('validates each row with the fields of its own row type', function () {
    $response = $this->postJson('/martis/api/resources/r-r-v-page-models', [
        'blocks' => [
            ['type' => 'r-r-v-hero', 'fields' => ['headline' => 'Welcome']],
            ['type' => 'r-r-v-stats', 'fields' => ['value' => 'many']],
            ['type' => 'r-r-v-hero', 'fields' => []],
            ['type' => 'r-r-v-stats', 'fields' => ['value' => 12]],
        ],
    ]);

    $response->assertStatus(422);
    expect(rrvErrorMessages($response))->toBe([
        'blocks.1.fields.value' => 'The Value field must be an integer.',
        'blocks.2.fields.headline' => 'The Headline field is required.',
    ]);
});

it('validates a row that names no type as the first row type, as every storage mode reads it', function () {
    $response = $this->postJson('/martis/api/resources/r-r-v-page-models', [
        'blocks' => [['fields' => ['value' => 3]]],
    ]);

    $response->assertStatus(422);
    expect(rrvErrorFields($response))->toBe(['blocks.0.fields.headline']);
});

it('rejects a row whose type names no row type instead of storing it', function () {
    $page = RRVPageModel::create(['blocks' => []]);

    $response = $this->putJson("/martis/api/resources/r-r-v-page-models/{$page->id}", [
        'blocks' => [
            ['type' => 'r-r-v-hero', 'fields' => ['headline' => 'Hi']],
            ['type' => 'banner', 'fields' => ['headline' => 'Sale']],
        ],
    ]);

    $response->assertStatus(422);
    expect(rrvErrorMessages($response))->toBe(['blocks.1.type' => 'The selected Row type is invalid.'])
        ->and($page->fresh()->blocks)->toBe([]);
});

// ---------------------------------------------------------------------------
// Context rules
// ---------------------------------------------------------------------------

it('applies creationRules() inside a row when the record is created', function () {
    $missing = $this->postJson('/martis/api/resources/r-r-v-page-models', [
        'codes' => [['type' => 'r-r-v-code', 'fields' => []]],
    ]);
    $missing->assertStatus(422);
    expect(rrvErrorFields($missing))->toBe(['codes.0.fields.code']);

    // updateRules() (max:3) do not apply on create.
    $this->postJson('/martis/api/resources/r-r-v-page-models', [
        'codes' => [['type' => 'r-r-v-code', 'fields' => ['code' => 'LONGER']]],
    ])->assertStatus(201);
});

it('applies updateRules() inside a row when the record is updated', function () {
    $page = RRVPageModel::create(['codes' => []]);
    $uri = "/martis/api/resources/r-r-v-page-models/{$page->id}";

    // creationRules() (required) do not apply on update.
    $this->putJson($uri, ['codes' => [['type' => 'r-r-v-code', 'fields' => []]]])->assertStatus(200);

    $long = $this->putJson($uri, ['codes' => [['type' => 'r-r-v-code', 'fields' => ['code' => 'LONGER']]]]);
    $long->assertStatus(422);
    expect(rrvErrorFields($long))->toBe(['codes.0.fields.code']);
});

// ---------------------------------------------------------------------------
// Which Repeaters validate their rows
// ---------------------------------------------------------------------------

it('does not validate the rows of a readonly Repeater, which writes none', function () {
    $stored = [['id' => 'a', 'type' => 'r-r-v-section', 'fields' => ['key' => 'hero']]];
    $page = RRVPageModel::create(['locked' => $stored]);

    $response = $this->putJson("/martis/api/resources/r-r-v-page-models/{$page->id}", [
        'locked' => [rrvSection([], ['id' => 'a'])],
    ]);

    $response->assertStatus(200);
    expect($page->fresh()->locked)->toBe($stored);
});

it('validates the rows of a Repeater whose fillUsing() callback writes them', function () {
    $response = $this->postJson('/martis/api/resources/r-r-v-page-models', [
        'custom' => [rrvSection(['title' => 'No key'])],
    ]);

    $response->assertStatus(422);
    expect(rrvErrorFields($response))->toBe(['custom.0.fields.key']);
});

it('validates a legacy flat row as the first row type, under its flat path', function () {
    $invalid = $this->postJson('/martis/api/resources/r-r-v-page-models', [
        'sections' => [['title' => 'No key']],
    ]);
    $invalid->assertStatus(422);
    expect(rrvErrorMessages($invalid))->toBe(['sections.0.key' => 'The Key field is required.']);

    $this->postJson('/martis/api/resources/r-r-v-page-models', [
        'sections' => [['key' => 'hero']],
    ])->assertStatus(201);
    expect(RRVPageModel::sole()->sections[0]['key'])->toBe('hero');
});

it('validates the rows of a Repeater inside a row', function () {
    $response = $this->postJson('/martis/api/resources/r-r-v-page-models', [
        'menus' => [[
            'type' => 'r-r-v-menu',
            'fields' => [
                'label' => 'Main',
                'links' => [
                    ['type' => 'r-r-v-link', 'fields' => ['url' => '/']],
                    ['type' => 'r-r-v-link', 'fields' => ['url' => '']],
                ],
            ],
        ]],
    ]);

    $response->assertStatus(422);
    expect(rrvErrorMessages($response))->toBe(['menus.0.fields.links.1.fields.url' => 'The URL field is required.']);
});

it('rejects too many or too few rows when the Repeater declares count rules, as the guide documents', function () {
    $row = ['type' => 'r-r-v-hero', 'fields' => ['headline' => 'Hi']];

    $tooMany = $this->postJson('/martis/api/resources/r-r-v-page-models', ['limited' => [$row, $row, $row]]);
    $tooMany->assertStatus(422);
    expect(rrvErrorMessages($tooMany))->toBe(['limited' => 'The Limited field must not have more than 2 items.']);

    $none = $this->postJson('/martis/api/resources/r-r-v-page-models', ['limited' => []]);
    $none->assertStatus(422);
    expect(rrvErrorMessages($none))->toBe(['limited' => 'The Limited field must have at least 1 items.']);

    $this->postJson('/martis/api/resources/r-r-v-page-models', ['limited' => [$row, $row]])->assertStatus(201);
});

// ---------------------------------------------------------------------------
// Other request paths
// ---------------------------------------------------------------------------

it('validates the rows a multipart request sends as a JSON string next to a file', function () {
    $page = RRVPageModel::create(['sections' => []]);
    $send = fn (array $rows) => $this->call(
        'POST',
        "/martis/api/resources/r-r-v-page-models/{$page->id}",
        ['_method' => 'PUT', 'sections' => json_encode($rows)],
        [],
        ['cover' => UploadedFile::fake()->image('cover.png', 32, 32)],
        ['HTTP_ACCEPT' => 'application/json'],
    );

    $invalid = $send([rrvSection(['key' => 'hero']), rrvSection(['title' => 'No key'])]);
    $invalid->assertStatus(422);
    expect(rrvErrorFields($invalid))->toBe(['sections.1.fields.key'])
        ->and($page->fresh()->cover)->toBeNull();

    $send([rrvSection(['key' => 'hero'])])->assertStatus(200);
    $fresh = $page->fresh();
    expect($fresh->sections[0]['fields'])->toBe(['key' => 'hero'])
        ->and($fresh->cover)->toStartWith('covers/');
});

it('validates the rows of a Repeater on the HasMany inline form', function () {
    $client = RRVClientModel::create(['name' => 'Acme']);

    $response = $this->postJson("/martis/api/resources/r-r-v-client-models/{$client->id}/has-many/projects", [
        'name' => 'Site',
        'tasks' => [['type' => 'r-r-v-task', 'fields' => ['name' => '']]],
    ]);

    $response->assertStatus(422);
    expect(rrvErrorMessages($response))->toBe(['tasks.0.fields.name' => 'The Name field is required.'])
        ->and(RRVProjectModel::count())->toBe(0);
});

it('stores the rows of a HasMany Repeater created through the HasMany inline form', function () {
    $client = RRVClientModel::create(['name' => 'Acme']);

    $this->postJson("/martis/api/resources/r-r-v-client-models/{$client->id}/has-many/projects", [
        'name' => 'Site',
        'tasks' => [['type' => 'r-r-v-task', 'fields' => ['name' => 'Design']]],
    ])->assertStatus(201);

    $project = RRVProjectModel::sole();
    expect($project->client_id)->toBe($client->id)
        ->and($project->tasks()->pluck('name')->all())->toBe(['Design']);
});

it('applies a field\'s custom validation message on the HasMany inline form', function () {
    $client = RRVClientModel::create(['name' => 'Acme']);
    RRVProjectModel::create(['client_id' => $client->id, 'name' => 'Site']);

    $response = $this->postJson("/martis/api/resources/r-r-v-client-models/{$client->id}/has-many/projects", [
        'name' => 'Site',
    ]);

    $response->assertStatus(422);
    expect(rrvErrorMessages($response))->toBe(['name' => 'That project name is taken.']);
});

it('validates the rows of a pivot Repeater on attach', function () {
    $client = RRVClientModel::create(['name' => 'Acme']);
    $page = RRVPageModel::create(['name' => 'Home']);
    $uri = "/martis/api/resources/r-r-v-client-models/{$client->id}/belongs-to-many/pages/attach";

    $invalid = $this->postJson($uri, ['related_id' => $page->id, 'steps' => [rrvSection([])]]);
    $invalid->assertStatus(422);
    expect(rrvErrorMessages($invalid))->toBe(['steps.0.fields.key' => 'The Key field is required.'])
        ->and($client->pages()->count())->toBe(0);

    $this->postJson($uri, ['related_id' => $page->id, 'steps' => [rrvSection(['key' => 'intro'])]])->assertStatus(201);
    expect($client->pages()->sole()->pivot->steps[0]['fields'])->toBe(['key' => 'intro']);
});

it('validates the rows of a Repeater among an Action\'s fields', function () {
    $uri = '/martis/api/resources/r-r-v-client-models/actions/r-r-v-import-action';

    $invalid = $this->postJson($uri, ['resources' => [], 'fields' => ['rows' => [rrvSection([])]]]);
    $invalid->assertStatus(422);
    expect(rrvErrorMessages($invalid))->toBe(['rows.0.fields.key' => 'The Key field is required.']);

    $this->postJson($uri, ['resources' => [], 'fields' => ['rows' => [rrvSection(['key' => 'a'])]]])->assertStatus(200);
});

// ---------------------------------------------------------------------------
// HasMany storage writes the row's own fields only
// ---------------------------------------------------------------------------

it('writes only the row fields of a HasMany Repeater, never a foreign key or another column a row sends', function () {
    $mine = RRVProjectModel::create(['name' => 'Mine']);
    $other = RRVProjectModel::create(['name' => 'Other']);

    $this->putJson("/martis/api/resources/r-r-v-project-models/{$mine->id}", [
        'tasks' => [['type' => 'r-r-v-task', 'fields' => ['name' => 'Design', 'project_id' => $other->id, 'id' => 99]]],
    ])->assertStatus(200);

    $task = RRVTaskModel::sole();
    expect($task->project_id)->toBe($mine->id)
        ->and($task->id)->not->toBe(99)
        ->and($task->name)->toBe('Design')
        ->and($other->tasks()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// The rule set itself
// ---------------------------------------------------------------------------

it('builds the rules, messages and attribute names of the rows for a custom validator', function () {
    $field = Repeater::make('sections', 'Sections')->repeatables([RRVSection::make()]);

    $validation = $field->buildRowValidation(['sections' => [rrvSection([]), ['type' => 'nope']]], 'create');

    expect($validation['rules'])->toHaveKeys(['sections.0.fields.key', 'sections.0.fields.title', 'sections.1.type'])
        ->and($validation['rules'])->not->toHaveKey('sections.0.fields.note')
        ->and($validation['rules']['sections.0.fields.key'])->toBe(['required'])
        ->and($validation['rules']['sections.0.fields.title'])->toBe(['nullable', 'max:10'])
        ->and($validation['attributes'])->toBe([
            'sections.0.fields.key' => 'Key',
            'sections.0.fields.title' => 'Title',
            'sections.1.type' => 'Row type',
        ]);
});

class RRVShortCode extends Text
{
    public function validationMessages(): array
    {
        return ['code.max' => 'Codes are short.'];
    }
}

class RRVShortCodeRow extends Repeatable
{
    public function fields(Request $request): array
    {
        return [RRVShortCode::make('code', 'Code')->rules(['max:3'])];
    }
}

it('moves a row field\'s custom message to the field\'s path in every row', function () {
    $field = Repeater::make('codes')->repeatables([RRVShortCodeRow::make()]);

    $validation = $field->buildRowValidation(['codes' => [['fields' => ['code' => 'a']], ['fields' => ['code' => 'b']]]], 'create');

    expect($validation['messages'])->toBe([
        'codes.0.fields.code.max' => 'Codes are short.',
        'codes.1.fields.code.max' => 'Codes are short.',
    ]);
});

it('leaves a row field\'s unique() out, since a row has no stored record to exclude on update', function () {
    $tasks = RRVProjectModel::create(['name' => 'Site'])->tasks();
    $tasks->create(['uuid' => 'u-1', 'name' => 'Design']);
    $field = Repeater::make('tasks')->asHasMany()->uniqueField('uuid')->repeatables([new class extends Repeatable
    {
        public static ?string $model = RRVTaskModel::class;

        public function fields(Request $request): array
        {
            return [Text::make('name', 'Name')->required()->unique(['rrv_tasks', 'name'], 'That task exists.')];
        }
    }]);

    // The stored row sent back unchanged, as every update form does.
    $validation = $field->buildRowValidation(['tasks' => [['id' => 'u-1', 'fields' => ['name' => 'Design']]]], 'update');

    expect($validation['rules']['tasks.0.fields.name'])->toBe(['required'])
        ->and($validation['messages'])->toBe([]);
});
