<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionEventRedactor;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\BelongsToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Models\ActionEvent;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Resources\ActionEventResource;

// ===========================================================================
// Hidden values in the action event log (v2.0.1).
//
// Write: as in Nova, whose action events store their diffs through
// Orchestra\Sidekick\Eloquent\model_state() (each `$hidden` attribute
// becomes a SensitiveValue serialised as `******`), the model's `$hidden`
// attributes an action changed are stored masked, and a pivot action masks
// the pivot model's `$hidden` columns. The key stays in the diff.
//
// Read: a pivot action's event also masks the pivot columns whose pivot
// field the viewer may not see (canSee()), and every column when the viewer
// sees none of the many-to-many fields that list the pivot row.
// ===========================================================================

class AEHUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class AEHPivot extends Pivot
{
    protected $table = 'aeh_account_tag';

    protected $hidden = ['secret'];
}

class AEHAccount extends Model
{
    protected $table = 'aeh_accounts';

    protected $guarded = [];

    protected $hidden = ['api_token'];

    public $timestamps = false;

    public function tags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(AEHTag::class, 'aeh_account_tag', 'account_id', 'tag_id')
            ->using(AEHPivot::class)
            ->withPivot(['priority', 'grade', 'secret']);
    }
}

class AEHTag extends Model
{
    protected $table = 'aeh_tags';

    protected $guarded = [];

    public $timestamps = false;
}

class AEHRotateToken extends Action
{
    public ?string $name = 'Rotate Token';

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        foreach ($models as $account) {
            $account->forceFill(['name' => 'Rotated', 'api_token' => 'new-token'])->save();
        }

        return ActionResponse::message('Rotated.');
    }
}

class AEHQueuedRotateToken extends AEHRotateToken implements ShouldQueue
{
    public ?string $name = 'Queued Rotate Token';
}

class AEHRekeyPivot extends Action
{
    public ?string $name = 'Rekey Pivot';

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        foreach ($models as $tag) {
            $tag->pivot->priority = 'high';
            $tag->pivot->secret = 'new-secret';
            $tag->pivot->save();
        }

        return ActionResponse::message('Rekeyed.');
    }
}

/** What the viewer may see of the tags panel, per test. */
class AEHState
{
    public static bool $tagsVisible = true;
}

class AEHAccountResource extends Resource
{
    public static function model(): string
    {
        return AEHAccount::class;
    }

    public static function uriKey(): string
    {
        return 'aeh-accounts';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            Text::make('api_token'),
            BelongsToMany::make('Tags', 'tags', AEHTagResource::class)
                ->canSee(fn (): bool => AEHState::$tagsVisible)
                ->fields(fn () => [
                    Text::make('priority')->nullable(),
                    // Only the operator sees the grade.
                    Text::make('grade')->nullable()->canSee(fn (Request $r): bool => $r->user()?->getAttribute('email') === 'aeh-operator@example.com'),
                    Text::make('secret')->nullable(),
                ])
                ->actions(fn () => [AEHRekeyPivot::make()]),
        ];
    }

    public function actions(Request $request): array
    {
        return [AEHRotateToken::make(), AEHQueuedRotateToken::make()];
    }
}

class AEHTagResource extends Resource
{
    public static function model(): string
    {
        return AEHTag::class;
    }

    public static function uriKey(): string
    {
        return 'aeh-tags';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    AEHState::$tagsVisible = true;

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->timestamps();
        });
    }

    foreach (['aeh_accounts', 'aeh_tags', 'aeh_account_tag', 'martis_action_events'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('aeh_accounts', function ($t) {
        $t->id();
        $t->string('name');
        $t->string('api_token')->nullable();
    });
    Schema::create('aeh_tags', function ($t) {
        $t->id();
        $t->string('name');
    });
    Schema::create('aeh_account_tag', function ($t) {
        $t->id();
        $t->unsignedBigInteger('account_id');
        $t->unsignedBigInteger('tag_id');
        $t->string('priority')->nullable();
        $t->string('grade')->nullable();
        $t->string('secret')->nullable();
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
    foreach ([AEHAccountResource::class, AEHTagResource::class, ActionEventResource::class] as $class) {
        $registry->register($class);
    }

    Gate::define(ActionEventResource::GATE, fn ($user = null): bool => $user !== null);
    Resource::flushPolicyCache();
    ActionEventRedactor::flush();

    $this->operator = AEHUser::query()->create(['name' => 'Operator', 'email' => 'aeh-operator@example.com', 'password' => 'x']);
    $this->agent = AEHUser::query()->create(['name' => 'Agent', 'email' => 'aeh-agent@example.com', 'password' => 'x']);
    $this->actingAs($this->operator, 'web');

    $this->account = AEHAccount::query()->create(['name' => 'Account', 'api_token' => 'old-token']);
    $this->tag = AEHTag::query()->create(['name' => 'Tag']);
    $this->account->tags()->attach($this->tag->id, ['priority' => 'low', 'grade' => 'A', 'secret' => 'old-secret']);
});

afterEach(function () {
    foreach (['aeh_accounts', 'aeh_tags', 'aeh_account_tag', 'martis_action_events'] as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
    Resource::flushPolicyCache();
    ActionEventRedactor::flush();
});

/** The stored (raw) original / changes of the only event of an action. */
function aehStored(string $name): array
{
    $row = ActionEvent::query()->where('name', $name)->sole();

    return [$row->original, $row->changes];
}

it('stores the model hidden attributes an action changed masked, keeping the key', function (string $action, string $name) {
    $this->postJson("/martis/api/resources/aeh-accounts/actions/{$action}", ['resources' => [$this->account->id]])->assertOk();

    expect(AEHAccount::query()->find($this->account->id)->api_token)->toBe('new-token');

    [$original, $changes] = aehStored($name);

    expect($original)->toBe(['name' => 'Account', 'api_token' => ActionEventRedactor::MASK])
        ->and($changes)->toBe(['name' => 'Rotated', 'api_token' => ActionEventRedactor::MASK])
        // A queued action on the sync connection settles its `queued` event
        // (written before the job is dispatched).
        ->and(ActionEvent::query()->where('name', $name)->sole()->status)->toBe('completed');
})->with([
    'synchronous' => ['a-e-h-rotate-token', 'Rotate Token'],
    'queued' => ['a-e-h-queued-rotate-token', 'Queued Rotate Token'],
]);

it('stores the pivot model hidden columns a pivot action changed masked', function () {
    $this->postJson("/martis/api/resources/aeh-accounts/{$this->account->id}/belongs-to-many/tags/actions/a-e-h-rekey-pivot", [
        'resources' => [$this->tag->id],
    ])->assertOk();

    [$original, $changes] = aehStored('Rekey Pivot');

    expect($original)->toBe(['priority' => 'low', 'secret' => ActionEventRedactor::MASK])
        ->and($changes)->toBe(['priority' => 'high', 'secret' => ActionEventRedactor::MASK]);
});

function aehPivotEvent(): ActionEvent
{
    return ActionEvent::query()->create([
        'batch_id' => 'b',
        'user_id' => 1,
        'name' => 'Rekey Pivot',
        'actionable_type' => AEHAccount::class,
        'actionable_id' => (string) test()->account->id,
        'target_type' => AEHTag::class,
        'target_id' => (string) test()->tag->id,
        'model_type' => AEHPivot::class,
        'model_id' => '1',
        'fields' => [],
        'status' => 'completed',
        'exception' => '',
        'original' => ['priority' => 'low', 'grade' => 'A'],
        'changes' => ['priority' => 'high', 'grade' => 'B'],
    ]);
}

function aehReadChanges(ActionEvent $event): array
{
    ActionEventRedactor::flush();
    $value = test()->getJson('/martis/api/resources/action-events/'.$event->getKey())->assertOk()->json('data.changes');

    return is_string($value) ? json_decode($value, true) : (array) $value;
}

it('masks on read the pivot columns whose pivot field the viewer may not see', function () {
    $event = aehPivotEvent();

    expect(aehReadChanges($event))->toBe(['priority' => 'high', 'grade' => 'B']);

    $this->actingAs($this->agent, 'web');

    expect(aehReadChanges($event))->toBe(['priority' => 'high', 'grade' => ActionEventRedactor::MASK]);
});

it('masks every pivot column when the viewer sees no field that lists the pivot row', function () {
    $event = aehPivotEvent();
    AEHState::$tagsVisible = false;

    expect(aehReadChanges($event))->toBe(['priority' => ActionEventRedactor::MASK, 'grade' => ActionEventRedactor::MASK]);
});
