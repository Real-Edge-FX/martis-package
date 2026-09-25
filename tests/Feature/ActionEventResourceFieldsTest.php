<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Cache\MartisCache;
use Martis\Concerns\Actionable;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Models\ActionEvent;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Resources\ActionEventResource;

// ===========================================================================
// The action log reads as Nova 5's `ActionResource`: ID, Name, Initiated By
// (the user's name), Target (the target record's resource label and title,
// linked when the viewer may view it), Status (Waiting / Running / Finished /
// Failed), Original, Changes, Exception, Happened At, translated. The
// "Action Events" panel of an Actionable model keeps Nova's label.
// ===========================================================================

class AERUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

/** The users the Martis guard signs in, in a table of their own. */
class AERAdmin extends User
{
    protected $table = 'aer_admins';

    protected $guarded = [];
}

class AERProject extends Model
{
    use Actionable;

    protected $table = 'aer_projects';

    protected $guarded = [];

    public $timestamps = false;
}

class AERProjectResource extends Resource
{
    public static function model(): string
    {
        return AERProject::class;
    }

    public static function uriKey(): string
    {
        return 'aer-projects';
    }

    public static function singularLabel(): string
    {
        return 'Project';
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }
}

/** Lets nobody view the project "Secret". */
class AERProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AERProject $project): bool
    {
        return $project->getAttribute('title') !== 'Secret';
    }
}

function aerEvent(array $attributes = []): ActionEvent
{
    return ActionEvent::query()->create(array_merge([
        'batch_id' => 'batch',
        'user_id' => 1,
        'name' => 'Publish',
        'actionable_type' => AERProject::class,
        'actionable_id' => '1',
        'target_type' => AERProject::class,
        'target_id' => '1',
        'model_type' => AERProject::class,
        'model_id' => '1',
        'fields' => [],
        'status' => 'completed',
        'exception' => '',
        'original' => [],
        'changes' => [],
    ], $attributes));
}

/** @return array<string, mixed> The index row of `$event`. */
function aerRow(ActionEvent $event): array
{
    $rows = test()->getJson('/martis/api/resources/action-events?per_page=100')->assertOk()->json('data');

    foreach ($rows as $row) {
        if ((string) $row['id'] === (string) $event->getKey()) {
            return $row;
        }
    }

    throw new RuntimeException('Event not listed.');
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('users');
    Schema::create('users', function ($t) {
        $t->id();
        $t->string('name')->nullable();
        $t->string('email')->unique();
        $t->string('password');
        $t->timestamps();
    });

    Schema::dropIfExists('aer_admins');
    Schema::create('aer_admins', function ($t) {
        $t->id();
        $t->string('name')->nullable();
        $t->string('email')->unique();
        $t->string('password');
        $t->timestamps();
    });

    Schema::dropIfExists('aer_projects');
    Schema::create('aer_projects', function ($t) {
        $t->id();
        $t->string('title');
    });

    Schema::dropIfExists('martis_action_events');
    Schema::create('martis_action_events', function ($t) {
        $t->id();
        $t->string('batch_id')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('name');
        $t->string('actionable_type')->nullable();
        $t->string('actionable_id')->nullable();
        $t->string('target_type')->nullable();
        $t->string('target_id')->nullable();
        $t->string('model_type')->nullable();
        $t->string('model_id')->nullable();
        $t->text('fields')->nullable();
        $t->string('status')->default('completed');
        $t->text('exception')->nullable();
        $t->text('original')->nullable();
        $t->text('changes')->nullable();
        $t->timestamps();
    });

    config(['auth.providers.users.model' => AERUser::class]);

    $this->operator = AERUser::query()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'password' => 'x']);
    $this->actingAs($this->operator, 'web');

    $this->project = AERProject::query()->create(['title' => 'Apollo']);
    $this->secret = AERProject::query()->create(['title' => 'Secret']);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(AERProjectResource::class);
    $registry->register(ActionEventResource::class);

    Gate::define(ActionEventResource::GATE, fn ($user = null): bool => $user !== null);
    Gate::policy(AERProject::class, AERProjectPolicy::class);
    Resource::flushPolicyCache();
    app(MartisCache::class)->clear('schema');
});

afterEach(function () {
    Schema::dropIfExists('aer_projects');
    Schema::dropIfExists('aer_admins');
    Schema::dropIfExists('martis_action_events');
    app(ResourceRegistry::class)->flush();
    Resource::flushPolicyCache();
    App::setLocale('en');
});

it('lists Nova\'s columns, in Nova\'s order and with Nova\'s labels', function () {
    $index = $this->getJson('/martis/api/resources/action-events/schema')->assertOk()->json('data.fieldsForIndex');

    expect(array_column($index, 'label'))->toBe(['ID', 'Name', 'Initiated By', 'Target', 'Status', 'Happened At'])
        ->and(array_column($index, 'attribute'))->toBe(['id', 'name', 'user_id', 'target', 'status', 'created_at']);
});

it('shows Nova\'s detail fields: no batch id, no raw actionable columns', function () {
    $fields = (new ActionEventResource(aerEvent()))->fields(request());

    expect(array_map(fn ($f) => $f->label(), $fields))->toBe([
        'ID', 'Name', 'Initiated By', 'Target', 'Status', 'Original', 'Changes', 'Exception', 'Happened At',
    ]);
});

it('shows the name of the user who ran the action', function () {
    $row = aerRow(aerEvent(['user_id' => $this->operator->getKey()]));

    expect($row['user_id'])->toBe('Ada Lovelace');
});

it('falls back to the email, then to the stored id when the user is gone', function () {
    $nameless = AERUser::query()->create(['name' => null, 'email' => 'nameless@example.com', 'password' => 'x']);

    expect(aerRow(aerEvent(['user_id' => $nameless->getKey()]))['user_id'])->toBe('nameless@example.com')
        ->and((string) aerRow(aerEvent(['user_id' => 999]))['user_id'])->toBe('999');
});

it('resolves the user through the Martis guard\'s user model, not the site\'s', function () {
    config(['martis.guard' => 'aer_admin']);
    config(['auth.guards.aer_admin' => ['driver' => 'session', 'provider' => 'aer_admins']]);
    config(['auth.providers.aer_admins' => ['driver' => 'eloquent', 'model' => AERAdmin::class]]);

    // Same id in both tables: the admin is the one who acted in the panel.
    $admin = AERAdmin::query()->create(['id' => $this->operator->getKey(), 'name' => 'Panel Admin', 'email' => 'admin@example.com', 'password' => 'x']);

    expect(ActionEventResource::initiatorName(aerEvent(['user_id' => $admin->getKey()])))->toBe('Panel Admin');
});

it('shows the target as the resource title, linked, when the viewer may view it', function () {
    $row = aerRow(aerEvent(['target_id' => (string) $this->project->getKey()]));

    expect($row['target'])->toMatchArray([
        'id' => (string) $this->project->getKey(),
        'title' => 'Apollo',
        'resourceType' => 'aer-projects',
        'resourceLabel' => 'Project',
    ]);
});

it('shows only the label and the id, unlinked, for a target the viewer may not view', function () {
    $id = (string) $this->secret->getKey();
    $row = aerRow(aerEvent(['target_id' => $id]));

    expect($row['target']['title'])->toBe('Project: '.$id)
        ->and($row['target']['resourceType'])->toBeNull()
        ->and(json_encode($row['target']))->not->toContain('Secret');
});

it('shows the label and the id for a target record that is gone or has no resource', function () {
    expect(aerRow(aerEvent(['target_id' => '404']))['target'])->toMatchArray(['title' => 'Project: 404', 'resourceType' => null])
        ->and(aerRow(aerEvent(['target_type' => 'App\\Models\\Invoice', 'target_id' => '7']))['target'])
        ->toMatchArray(['title' => 'Invoice: 7', 'resourceType' => null])
        ->and(aerRow(aerEvent(['target_type' => null, 'target_id' => null]))['target'])->toBeNull();
});

it('labels the status as Nova does', function (string $stored, string $label) {
    expect(aerRow(aerEvent(['status' => $stored]))['status'])->toBe($label);
})->with([
    ['queued', 'Waiting'],
    ['running', 'Running'],
    ['completed', 'Finished'],
    ['finished', 'Finished'],
    ['failed', 'Failed'],
    ['denied', 'Denied'],
    ['paused', 'Paused'],
]);

it('spins on Waiting and Running and flags Failed and Denied', function () {
    $status = collect($this->getJson('/martis/api/resources/action-events/schema')->json('data.fieldsForIndex'))
        ->firstWhere('attribute', 'status');

    expect($status['type'])->toBe('status')
        ->and($status['loadingWhen'])->toBe(['Waiting', 'Running'])
        ->and($status['failedWhen'])->toBe(['Failed', 'Denied']);
});

it('shows Original and Changes only for an event that holds a diff', function () {
    $empty = aerEvent();
    $diff = aerEvent(['original' => ['title' => 'Old'], 'changes' => ['title' => 'New']]);

    $emptyDetail = $this->getJson('/martis/api/resources/action-events/'.$empty->getKey())->assertOk()->json('data');
    $diffDetail = $this->getJson('/martis/api/resources/action-events/'.$diff->getKey())->assertOk()->json('data');

    expect($emptyDetail)->not->toHaveKey('original')
        ->and($emptyDetail)->not->toHaveKey('changes')
        ->and($diffDetail['original'])->toBe(['title' => 'Old'])
        ->and($diffDetail['changes'])->toBe(['title' => 'New']);
});

it('translates the labels, the statuses and the panel label', function (string $locale, array $labels, string $finished, string $panel) {
    App::setLocale($locale);

    $fields = (new ActionEventResource(aerEvent()))->fields(request());

    expect(array_map(fn ($f) => $f->label(), $fields))->toBe($labels)
        ->and(ActionEventResource::statusLabel('completed'))->toBe($finished)
        ->and(ActionEventResource::label())->toBe($panel);

    $panelField = collect((new AERProjectResource($this->project))->resolveDetailFields(request()))->last();
    expect($panelField->label())->toBe($panel);
})->with([
    'en' => ['en', ['ID', 'Name', 'Initiated By', 'Target', 'Status', 'Original', 'Changes', 'Exception', 'Happened At'], 'Finished', 'Action Events'],
    'pt_PT' => ['pt_PT', ['ID', 'Nome', 'Iniciada por', 'Alvo', 'Estado', 'Original', 'Alterações', 'Exceção', 'Ocorreu em'], 'Concluída', 'Eventos de ações'],
    'pt_BR' => ['pt_BR', ['ID', 'Nome', 'Iniciada por', 'Alvo', 'Status', 'Original', 'Alterações', 'Exceção', 'Ocorreu em'], 'Concluída', 'Eventos de ações'],
]);
