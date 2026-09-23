<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Actions\Jobs\ExecuteAction;
use Martis\Fields\BelongsToMany;
use Martis\Fields\Field;
use Martis\Fields\Repeatable;
use Martis\Fields\Repeater;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// The fields of an Action the request cannot write (v1.38.0).
//
// The fields endpoints served every field of an Action, and the run
// validated every field and handed handle() every value the request sent: a
// field the user cannot see (canSee()) was listed in the modal, validated
// and set from the request, and so were a readonly or computed field and the
// fields of a Repeater's rows. As Nova resolves an Action's fields, a field
// the user cannot see is now left out of the modal, and none of these fields
// is validated or takes its value from the request: handle() receives its
// default(), or nothing. A Repeater's rows are all new rows and follow the
// Repeater's row rules. A key that names no field of the Action (the extra
// fields of a custom component) still reaches handle() as sent.
// ===========================================================================

class AFVRecorder
{
    /** @var array<string, mixed>|null */
    public static ?array $received = null;
}

class AFVRow extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            Text::make('name', 'Name')->required(),
            Text::make('status', 'Status')->readonly()->default('draft'),
            Text::make('code', 'Code')->readonly(),
            Text::make('total', 'Total')->computed(fn () => 'computed'),
            Text::make('tier', 'Tier')->canSee(fn () => false)->default('basic'),
            Text::make('secret', 'Secret')->canSee(fn () => false)->rules(['required']),
            Text::make('slug', 'Slug')->immutable()->nullable(),
        ];
    }
}

/** @return list<Field> */
function afvActionFields(): array
{
    return [
        Text::make('note')->nullable(),
        Text::make('secret')->canSee(fn () => false)->rules(['required']),
        Text::make('tier')->canSee(fn () => false)->default('basic'),
        Text::make('code')->readonly()->default('R-1')->rules(['max:3']),
        Text::make('label')->readonly(),
        Text::make('total')->computed(fn () => 'computed'),
        Repeater::make('rows', 'Rows')->repeatables([AFVRow::make()]),
    ];
}

class AFVReportAction extends Action
{
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        AFVRecorder::$received = $fields->all();

        return ActionResponse::message('Done.');
    }

    public function fields(Request $request): array
    {
        return afvActionFields();
    }
}

class AFVQueuedReportAction extends AFVReportAction implements ShouldQueue {}

class AFVPost extends Model
{
    protected $table = 'afv_posts';

    protected $guarded = [];

    public $timestamps = false;

    public function tags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(AFVTag::class, 'afv_post_tag', 'post_id', 'tag_id');
    }
}

class AFVTag extends Model
{
    protected $table = 'afv_tags';

    protected $guarded = [];

    public $timestamps = false;
}

class AFVPostResource extends Resource
{
    public static function model(): string
    {
        return AFVPost::class;
    }

    public static function uriKey(): string
    {
        return 'afv-posts';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            BelongsToMany::make('Tags', 'tags')
                ->relatedResource('afv-tags')
                ->actions(fn () => [AFVReportAction::make()->standalone()]),
        ];
    }

    public function actions(Request $request): array
    {
        return [
            AFVReportAction::make()->standalone(),
            AFVQueuedReportAction::make()->standalone(),
        ];
    }
}

class AFVTagResource extends Resource
{
    public static function model(): string
    {
        return AFVTag::class;
    }

    public static function uriKey(): string
    {
        return 'afv-tags';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    config()->set('martis.action_events.enabled', false);

    foreach (['afv_post_tag', 'afv_tags', 'afv_posts'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('afv_posts', function ($table) {
        $table->id();
        $table->string('title');
    });
    Schema::create('afv_tags', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('afv_post_tag', function ($table) {
        $table->unsignedBigInteger('post_id');
        $table->unsignedBigInteger('tag_id');
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(AFVPostResource::class);
    $registry->register(AFVTagResource::class);

    AFVRecorder::$received = null;
    $this->post = AFVPost::create(['title' => 'Post']);
});

afterEach(function () {
    foreach (['afv_post_tag', 'afv_tags', 'afv_posts'] as $table) {
        Schema::dropIfExists($table);
    }
});

/** The URI of an Action route: the resource's, or the pivot action's of the Tags panel. */
function afvActionUri(string $kind, AFVPost $post, string $suffix = ''): string
{
    return $kind === 'resource'
        ? "/martis/api/resources/afv-posts/actions/a-f-v-report-action{$suffix}"
        : "/martis/api/resources/afv-posts/{$post->id}/belongs-to-many/tags/actions/a-f-v-report-action{$suffix}";
}

/** What a forged request sends: a value for every field, and a key no field names. */
const AFV_FORGED = [
    'note' => 'Hello',
    'secret' => 'forged',
    'tier' => 'forged',
    'code' => 'forged',
    'label' => 'forged',
    'total' => 'forged',
    'extra' => 'kept',
    'rows' => [
        ['type' => 'a-f-v-row', 'fields' => [
            'name' => 'Row',
            'status' => 'forged',
            'code' => 'forged',
            'total' => 'forged',
            'tier' => 'forged',
            'secret' => 'forged',
            'slug' => 'new-slug',
        ]],
    ],
];

/** What handle() receives for it. */
const AFV_RECEIVED = [
    'note' => 'Hello',
    'tier' => 'basic',
    'code' => 'R-1',
    'extra' => 'kept',
    'rows' => [
        ['type' => 'a-f-v-row', 'fields' => ['name' => 'Row', 'status' => 'draft', 'tier' => 'basic', 'slug' => 'new-slug']],
    ],
];

$kinds = ['resource action' => 'resource', 'pivot action' => 'pivot'];

it('leaves an Action field the user cannot see out of the modal', function (string $kind) {
    $fields = $this->getJson(afvActionUri($kind, $this->post, '/fields'))->assertOk()->json('data.fields');

    expect(array_column($fields, 'attribute'))->toBe(['note', 'code', 'label', 'total', 'rows']);
})->with($kinds);

it('hands handle() only the values the request may set, and the defaults of the others', function (string $kind) {
    $this->postJson(afvActionUri($kind, $this->post), ['resources' => [], 'fields' => AFV_FORGED])->assertOk();

    expect(AFVRecorder::$received)->toEqual(AFV_RECEIVED)
        ->and(AFVRecorder::$received)->not->toHaveKeys(['secret', 'label', 'total'])
        ->and(AFVRecorder::$received['rows'][0]['fields'])->not->toHaveKeys(['code', 'total', 'secret']);
})->with($kinds);

it('does not validate an Action field the request may not set', function (string $kind) {
    // `secret` is required and `code` allows three characters, but neither
    // is taken from the request; the same goes for the row's hidden `secret`.
    $this->postJson(afvActionUri($kind, $this->post), [
        'resources' => [],
        'fields' => ['code' => 'far too long', 'rows' => [['type' => 'a-f-v-row', 'fields' => ['name' => 'Row']]]],
    ])->assertOk();

    expect(AFVRecorder::$received)->toEqual([
        'tier' => 'basic',
        'code' => 'R-1',
        'rows' => [['type' => 'a-f-v-row', 'fields' => ['name' => 'Row', 'status' => 'draft', 'tier' => 'basic']]],
    ]);
})->with($kinds);

it('still validates the Action fields the request sets', function (string $kind) {
    $response = $this->postJson(afvActionUri($kind, $this->post), [
        'resources' => [],
        'fields' => ['rows' => [['type' => 'a-f-v-row', 'fields' => ['name' => '']]]],
    ]);

    $response->assertStatus(422);
    expect(collect($response->json('errors'))->pluck('field')->all())->toBe(['rows.0.fields.name'])
        ->and(AFVRecorder::$received)->toBeNull();
})->with($kinds);

it('queues an Action with the values handle() receives', function () {
    Queue::fake();

    $this->postJson('/martis/api/resources/afv-posts/actions/a-f-v-queued-report-action', ['resources' => [], 'fields' => AFV_FORGED])
        ->assertOk();

    Queue::assertPushed(ExecuteAction::class, function (ExecuteAction $job): bool {
        expect($job->fields)->toEqual(AFV_RECEIVED);

        return true;
    });
});
