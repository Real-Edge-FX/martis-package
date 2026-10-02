<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionEventRedactor;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\BelongsToMany;
use Martis\Fields\Field;
use Martis\Fields\Password;
use Martis\Fields\PasswordConfirmation;
use Martis\Fields\Repeatable;
use Martis\Fields\Repeater;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Models\ActionEvent;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// The action event log keeps the resolved action fields, not the raw input
// (F091, F108). It used to store `$request->input('fields')` verbatim: a
// Password field typed in a "Set new password" action, the values of fields
// the user cannot see and keys that name no field, all in plain text in
// martis_action_events.fields. The event now stores the values the run
// resolved for the visible fields, with a Password (or sensitive()) field
// masked as `******` and every other key left out. A pivot action's event does
// the same.
// ===========================================================================

class AEFRecorder
{
    /** @var array<string, mixed>|null */
    public static ?array $received = null;
}

class AEFAccount extends Model
{
    protected $table = 'aef_accounts';

    protected $guarded = [];

    public $timestamps = false;

    public function tags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(AEFTag::class, 'aef_account_tag', 'account_id', 'tag_id');
    }
}

class AEFTag extends Model
{
    protected $table = 'aef_tags';

    protected $guarded = [];

    public $timestamps = false;
}

class AEFRow extends Repeatable
{
    public function fields(Request $request): array
    {
        return [Text::make('label'), Password::make('pin')->nullable(), Text::make('token')->sensitive()->nullable()];
    }
}

/** @return list<Field> */
function aefFields(): array
{
    return [
        Password::make('new_password')->nullable(),
        PasswordConfirmation::make('new_password_confirmation')->nullable(),
        Text::make('note')->nullable(),
        Text::make('api_key')->sensitive()->nullable(),
        Text::make('hidden_note')->canSee(fn () => false)->nullable(),
        Repeater::make('rows')->repeatables([AEFRow::make()])->nullable(),
    ];
}

class AEFRotate extends Action
{
    public ?string $name = 'Rotate';

    public function fields(Request $request): array
    {
        return aefFields();
    }

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        AEFRecorder::$received = $fields->all();

        return ActionResponse::message('Rotated.');
    }
}

class AEFQueuedRotate extends AEFRotate implements ShouldQueue
{
    public ?string $name = 'Queued Rotate';
}

/** A queued action whose fields hold no secret. */
class AEFQueuedPlain extends Action implements ShouldQueue
{
    public ?string $name = 'Queued Plain';

    public function fields(Request $request): array
    {
        return [Text::make('note')->nullable()];
    }

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        return ActionResponse::message('Done.');
    }
}

/** A queue connection that keeps the payloads it is handed, as a real driver would store them. */
class AEFCaptureQueue extends NullQueue
{
    /** @var list<string> */
    public static array $payloads = [];

    public function push($job, $data = '', $queue = null)
    {
        self::$payloads[] = $this->createPayload($job, (string) ($queue ?? 'default'), $data);

        return null;
    }
}

class AEFFailingRotate extends AEFRotate
{
    public ?string $name = 'Failing Rotate';

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        throw new RuntimeException('boom');
    }
}

class AEFStandaloneRotate extends AEFRotate
{
    public ?string $name = 'Standalone Rotate';
}

class AEFPivotRotate extends AEFRotate
{
    public ?string $name = 'Pivot Rotate';
}

class AEFQueuedPivotRotate extends AEFRotate implements ShouldQueue
{
    public ?string $name = 'Queued Pivot Rotate';
}

class AEFAccountResource extends Resource
{
    public static function model(): string
    {
        return AEFAccount::class;
    }

    public static function uriKey(): string
    {
        return 'aef-accounts';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsToMany::make('Tags', 'tags', AEFTagResource::class)
                ->actions(fn () => [AEFPivotRotate::make(), AEFQueuedPivotRotate::make(), AEFQueuedPlain::make()]),
        ];
    }

    public function actions(Request $request): array
    {
        return [AEFRotate::make(), AEFQueuedRotate::make(), AEFQueuedPlain::make(), AEFFailingRotate::make(), AEFStandaloneRotate::make()->standalone()->onlyOnIndex()];
    }
}

class AEFTagResource extends Resource
{
    public static function model(): string
    {
        return AEFTag::class;
    }

    public static function uriKey(): string
    {
        return 'aef-tags';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    AEFRecorder::$received = null;

    foreach (['aef_accounts', 'aef_tags', 'aef_account_tag', 'martis_action_events'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('aef_accounts', function ($t) {
        $t->id();
        $t->string('name');
    });
    Schema::create('aef_tags', function ($t) {
        $t->id();
        $t->string('name');
    });
    Schema::create('aef_account_tag', function ($t) {
        $t->id();
        $t->unsignedBigInteger('account_id');
        $t->unsignedBigInteger('tag_id');
    });
    // The shape of stubs/create_martis_action_events_table.php.stub.
    Schema::create('martis_action_events', function ($t) {
        $t->id();
        $t->string('batch_id')->index();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('name');
        $t->string('actionable_type')->nullable();
        $t->string('actionable_id')->nullable();
        $t->string('target_type')->nullable();
        $t->string('target_id')->nullable();
        $t->string('model_type')->nullable();
        $t->string('model_id')->nullable();
        $t->text('fields');
        $t->string('status', 25)->default('running');
        $t->text('exception');
        $t->text('original');
        $t->text('changes');
        $t->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(AEFAccountResource::class);
    $registry->register(AEFTagResource::class);

    $this->account = AEFAccount::query()->create(['name' => 'Account']);
    $this->tag = AEFTag::query()->create(['name' => 'Tag']);
    $this->account->tags()->attach($this->tag->id);
});

afterEach(function () {
    foreach (['aef_accounts', 'aef_tags', 'aef_account_tag', 'martis_action_events'] as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
});

const AEF_INPUT = [
    'new_password' => 'Sup3r-secret!',
    'new_password_confirmation' => 'Sup3r-secret!',
    'note' => 'rotated by ops',
    'api_key' => 'sk_live_123',
    'hidden_note' => 'not for the log',
    'unknown_key' => 'posted by a custom component',
    'rows' => [['type' => 'a-e-f-row', 'fields' => ['label' => 'primary', 'pin' => '4821', 'token' => 'tok-1']]],
];

/** What the log keeps of AEF_INPUT. */
function aefLogged(): array
{
    return [
        'new_password' => ActionEventRedactor::MASK,
        'new_password_confirmation' => ActionEventRedactor::MASK,
        'note' => 'rotated by ops',
        'api_key' => ActionEventRedactor::MASK,
        'rows' => [['type' => 'a-e-f-row', 'fields' => ['label' => 'primary', 'pin' => ActionEventRedactor::MASK, 'token' => ActionEventRedactor::MASK]]],
    ];
}

function aefEvent(string $name): ActionEvent
{
    return ActionEvent::query()->where('name', $name)->orderByDesc('id')->firstOrFail();
}

it('stores the masked values of the visible fields, not the raw input', function () {
    $this->postJson('/martis/api/resources/aef-accounts/actions/a-e-f-rotate', [
        'resources' => [$this->account->id],
        'fields' => AEF_INPUT,
    ])->assertOk();

    expect(aefEvent('Rotate')->fields)->toBe(aefLogged());
});

it('still hands the action the real values', function () {
    $this->postJson('/martis/api/resources/aef-accounts/actions/a-e-f-rotate', [
        'resources' => [$this->account->id],
        'fields' => AEF_INPUT,
    ])->assertOk();

    expect(AEFRecorder::$received['new_password'])->toBe('Sup3r-secret!')
        ->and(AEFRecorder::$received['api_key'])->toBe('sk_live_123')
        ->and(AEFRecorder::$received['rows'][0]['fields']['pin'])->toBe('4821');
});

it('never stores a secret in the table', function () {
    $this->postJson('/martis/api/resources/aef-accounts/actions/a-e-f-rotate', [
        'resources' => [$this->account->id],
        'fields' => AEF_INPUT,
    ])->assertOk();

    $stored = (string) json_encode(ActionEvent::query()->get()->map->getAttributes()->all());

    foreach (['Sup3r-secret!', 'sk_live_123', '4821', 'tok-1', 'not for the log', 'posted by a custom component'] as $leak) {
        expect($stored)->not->toContain($leak);
    }
});

it('stores the masked fields of a failed run', function () {
    $this->postJson('/martis/api/resources/aef-accounts/actions/a-e-f-failing-rotate', [
        'resources' => [$this->account->id],
        'fields' => AEF_INPUT,
    ])->assertStatus(500);

    $event = aefEvent('Failing Rotate');

    expect($event->status)->toBe('failed')
        ->and($event->fields)->toBe(aefLogged());
});

it('stores the masked fields of a queued run', function () {
    $this->postJson('/martis/api/resources/aef-accounts/actions/a-e-f-queued-rotate', [
        'resources' => [$this->account->id],
        'fields' => AEF_INPUT,
    ])->assertOk();

    // Queued, then settled by the job on the sync connection: one row, masked.
    expect(aefEvent('Queued Rotate')->fields)->toBe(aefLogged());
});

it('stores the masked fields of a standalone run', function () {
    $this->postJson('/martis/api/resources/aef-accounts/actions/a-e-f-standalone-rotate', [
        'resources' => [],
        'fields' => AEF_INPUT,
    ])->assertOk();

    $event = aefEvent('Standalone Rotate');

    expect($event->actionable_type)->toBeNull()
        ->and($event->fields)->toBe(aefLogged());
});

it('stores the masked fields of a pivot action, synchronous and queued', function (string $uri, string $name) {
    $this->postJson("/martis/api/resources/aef-accounts/{$this->account->id}/belongs-to-many/tags/actions/{$uri}", [
        'resources' => [$this->tag->id],
        'fields' => AEF_INPUT,
    ])->assertOk();

    expect(aefEvent($name)->fields)->toBe(aefLogged());
})->with([
    'synchronous' => ['a-e-f-pivot-rotate', 'Pivot Rotate'],
    'queued' => ['a-e-f-queued-pivot-rotate', 'Queued Pivot Rotate'],
]);

it('leaves out a field the run did not receive and keeps an empty secret empty', function () {
    // An empty string reaches the run as null (ConvertEmptyStringsToNull):
    // the log does not claim a secret was set.
    $this->postJson('/martis/api/resources/aef-accounts/actions/a-e-f-rotate', [
        'resources' => [$this->account->id],
        'fields' => ['note' => 'only a note', 'api_key' => ''],
    ])->assertOk();

    expect(aefEvent('Rotate')->fields)->toBe(['note' => 'only a note', 'api_key' => null]);
});

it('stores an empty list for an action that sends no fields', function () {
    $this->postJson('/martis/api/resources/aef-accounts/actions/a-e-f-rotate', [
        'resources' => [$this->account->id],
    ])->assertOk();

    expect(aefEvent('Rotate')->fields)->toBe([]);
});

// ---- the redactor and the flag -------------------------------------------

it('marks Password and PasswordConfirmation sensitive, and any field on request', function () {
    expect(Password::make('p')->isSensitive())->toBeTrue()
        ->and(PasswordConfirmation::make('p')->isSensitive())->toBeTrue()
        ->and(Text::make('t')->isSensitive())->toBeFalse()
        ->and(Text::make('t')->sensitive()->isSensitive())->toBeTrue()
        ->and(Password::make('p')->sensitive(false)->isSensitive())->toBeFalse();
});

it('keeps the logged values of the visible fields only', function () {
    $logged = ActionEventRedactor::loggableFields(
        aefFields(),
        ['note' => 'n', 'hidden_note' => 'h', 'unknown' => 'u', 'new_password' => 'p'],
        Request::create('/'),
    );

    expect($logged)->toBe(['new_password' => ActionEventRedactor::MASK, 'note' => 'n']);
});

it('masks inside the rows of a Repeater, and a nested Repeater too', function () {
    $nested = new class extends Repeatable
    {
        public function fields(Request $request): array
        {
            return [Text::make('label'), Password::make('pin'), Repeater::make('children')->repeatables([AEFRow::make()])];
        }
    };

    $logged = ActionEventRedactor::loggableFields(
        [Repeater::make('rows')->repeatables([$nested])],
        ['rows' => [['type' => 'x', 'fields' => ['label' => 'a', 'pin' => '1', 'children' => [['type' => 'a-e-f-row', 'fields' => ['pin' => '2', 'token' => 't', 'label' => 'l']]]]]]],
        Request::create('/'),
    );

    expect($logged['rows'][0]['fields'])->toBe([
        'label' => 'a',
        'pin' => ActionEventRedactor::MASK,
        'children' => [['type' => 'a-e-f-row', 'fields' => ['pin' => ActionEventRedactor::MASK, 'token' => ActionEventRedactor::MASK, 'label' => 'l']]],
    ]);
});

// ---- a queued run: the job payload is stored by the queue driver --------------------------

/** Run a queued action with a driver that keeps the payload, and return what it was handed. */
function aefQueuedPayload(string $uri, array $fields, ?string $pivotTag = null): array
{
    AEFCaptureQueue::$payloads = [];
    config()->set('queue.default', 'aef-capture');
    config()->set('queue.connections.aef-capture', ['driver' => 'aef-capture']);
    app('queue')->addConnector('aef-capture', fn () => new class implements ConnectorInterface
    {
        public function connect(array $config)
        {
            return new AEFCaptureQueue;
        }
    });

    $url = $pivotTag === null
        ? "/martis/api/resources/aef-accounts/actions/{$uri}"
        : '/martis/api/resources/aef-accounts/'.test()->account->id."/belongs-to-many/tags/actions/{$uri}";

    test()->postJson($url, ['resources' => [$pivotTag ?? test()->account->id], 'fields' => $fields])->assertOk();

    expect(AEFCaptureQueue::$payloads)->toHaveCount(1);

    return json_decode(AEFCaptureQueue::$payloads[0], true);
}

it('encrypts the payload of a queued run that carries a secret, so no queue store holds it in plain text', function (string $uri, ?bool $pivot) {
    $payload = aefQueuedPayload($uri, AEF_INPUT, $pivot ? (string) $this->tag->id : null);

    // What the driver stores holds none of the secrets: not the password, the sensitive text or the row's pin ...
    $stored = json_encode($payload);
    foreach (['Sup3r-secret!', 'sk_live_123', '4821', 'tok-1'] as $secret) {
        expect($stored)->not->toContain($secret);
    }
    expect($stored)->not->toContain('new_password');

    // ... and the worker still reads them: the command decrypts to the job with the real values.
    $job = unserialize(decrypt($payload['data']['command']));
    expect($job->fields['new_password'])->toBe('Sup3r-secret!')
        ->and($job->fields['api_key'])->toBe('sk_live_123')
        ->and($job->fields['rows'][0]['fields']['pin'])->toBe('4821');
})->with([
    'resource action' => ['a-e-f-queued-rotate', null],
    'pivot action' => ['a-e-f-queued-pivot-rotate', true],
]);

it('encrypts a queued run whose only secret is a sensitive() text field or a sensitive row attribute', function (array $fields) {
    $payload = aefQueuedPayload('a-e-f-queued-rotate', $fields);

    expect(json_encode($payload))->not->toContain('sk_live_123')->not->toContain('tok-9')
        ->and(unserialize(decrypt($payload['data']['command'])))->toBeObject();
})->with([
    'sensitive text' => [['api_key' => 'sk_live_123']],
    'sensitive row attribute' => [['rows' => [['type' => 'a-e-f-row', 'fields' => ['label' => 'x', 'token' => 'tok-9']]]]],
]);

it('leaves the payload of a queued run with no secret as it was, and when the secret fields are empty', function (string $uri, array $fields) {
    $payload = aefQueuedPayload($uri, $fields);

    // Plain serialized command: the worker needs no key, nothing was encrypted without cause.
    expect($payload['data']['command'])->toStartWith('O:')
        ->and($payload['data']['command'])->toContain('rotated by ops');
})->with([
    'an action with no sensitive field' => ['a-e-f-queued-plain', ['note' => 'rotated by ops']],
    'sensitive fields left empty' => ['a-e-f-queued-rotate', ['note' => 'rotated by ops', 'new_password' => '', 'api_key' => '']],
]);
