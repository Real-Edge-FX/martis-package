<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException as LaravelValidationException;
use Martis\Exceptions\ValidationException;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Resources\DatabaseErrorResponse;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// A restore is a write like any other: the row re-enters every partial
// unique index built on `deleted_at IS NULL`, so a collision with a live
// record is the most likely database failure of the action. Up to v2.23.0
// `restore()` was the only write of the controller with no QueryException
// guard, so the collision escaped as a raw 500 (consumer report
// 2026-11-18). It now answers 422 on the colliding field, as store() and
// update() do, and `beforeRestore()` / `afterRestore()` give a resource a
// supported place to refuse a restore.
// ===========================================================================

class RdeChannelModel extends Model
{
    use SoftDeletes;

    protected $table = 'rde_channels';

    protected $guarded = [];

    public $timestamps = false;
}

class RdeChannelResource extends Resource
{
    public static function model(): string
    {
        return RdeChannelModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('label')->nullable(),
            // No unique() rule: the partial index is the only guard.
            Text::make('kind')->nullable(),
        ];
    }
}

/** A resource that refuses the restore from its hook, as a consumer would. */
class RdeGuardedChannelResource extends RdeChannelResource
{
    public static function uriKey(): string
    {
        return 'rde-guarded-channels';
    }

    public function beforeRestore(Model $model, Request $request): void
    {
        throw ValidationException::fromFieldErrors(['kind' => ['This kind is taken.']]);
    }
}

/** A resource whose afterRestore() records that it ran. */
class RdeHookedChannelResource extends RdeChannelResource
{
    /** @var list<string> */
    public static array $calls = [];

    public static function uriKey(): string
    {
        return 'rde-hooked-channels';
    }

    public function beforeRestore(Model $model, Request $request): void
    {
        static::$calls[] = 'before:'.$model->getKey();
    }

    public function afterRestore(Model $model, Request $request): void
    {
        static::$calls[] = 'after:'.$model->getKey();
    }
}

/** A resource whose afterRestore() fails after the row was restored. */
class RdeFailingAfterChannelResource extends RdeChannelResource
{
    public static function uriKey(): string
    {
        return 'rde-failing-after-channels';
    }

    public function afterRestore(Model $model, Request $request): void
    {
        throw new RuntimeException('hook exploded at /var/www/secret/path');
    }
}

/** A resource whose beforeRestore() aborts with an HTTP status, as `abort(403)` does. */
class RdeAbortingChannelResource extends RdeChannelResource
{
    public static function uriKey(): string
    {
        return 'rde-aborting-channels';
    }

    public function beforeRestore(Model $model, Request $request): void
    {
        abort(403, 'Custom reason');
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    RdeHookedChannelResource::$calls = [];

    Schema::dropIfExists('rde_channels');
    Schema::create('rde_channels', function ($table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->default(1);
        $table->string('label')->nullable();
        $table->string('kind')->nullable();
        $table->softDeletes();
    });

    // The consumer's index: one live channel per tenant and kind, trashed
    // rows excluded.
    DB::statement('CREATE UNIQUE INDEX rde_channels_tenant_kind_unique ON rde_channels (tenant_id, kind) WHERE deleted_at IS NULL');

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(RdeChannelResource::class);
    $registry->register(RdeGuardedChannelResource::class);
    $registry->register(RdeHookedChannelResource::class);
    $registry->register(RdeFailingAfterChannelResource::class);
    $registry->register(RdeAbortingChannelResource::class);
});

afterEach(function () {
    Schema::dropIfExists('rde_channels');
});

it('answers a restore that collides with a partial unique index with a 422 on the field', function () {
    $trashed = RdeChannelModel::create(['label' => 'Original', 'kind' => 'casa_sapo']);
    $trashed->delete();
    RdeChannelModel::create(['label' => 'Replacement', 'kind' => 'casa_sapo']);

    $response = $this->putJson("/martis/api/resources/rde-channel-models/{$trashed->id}/restore");

    $response->assertStatus(422)
        ->assertJsonPath('message', DatabaseErrorResponse::UNIQUE_MESSAGE);

    $errors = [];
    foreach ($response->json('errors') as $error) {
        $errors[$error['field']] = $error['message'];
    }

    expect($errors)->toHaveKey('kind')
        ->and(RdeChannelModel::withTrashed()->find($trashed->id)->trashed())->toBeTrue();
});

it('restores a record that collides with nothing', function () {
    $trashed = RdeChannelModel::create(['label' => 'Original', 'kind' => 'casa_sapo']);
    $trashed->delete();

    $this->putJson("/martis/api/resources/rde-channel-models/{$trashed->id}/restore")
        ->assertStatus(200)
        ->assertJsonPath('meta.message', RdeChannelResource::restoredMessage());

    expect(RdeChannelModel::withTrashed()->find($trashed->id)->trashed())->toBeFalse();
});

it('answers 422 when beforeRestore() refuses the restore, and leaves the row trashed', function () {
    $trashed = RdeChannelModel::create(['label' => 'Original', 'kind' => 'casa_sapo']);
    $trashed->delete();

    $response = $this->putJson("/martis/api/resources/rde-guarded-channels/{$trashed->id}/restore");

    $response->assertStatus(422);
    expect(RdeChannelModel::withTrashed()->find($trashed->id)->trashed())->toBeTrue();
});

it('answers 422 when a restoring model event throws Laravel validation, not a 500', function () {
    $trashed = RdeChannelModel::create(['label' => 'Original', 'kind' => 'casa_sapo']);
    $trashed->delete();

    RdeChannelModel::restoring(function (): void {
        throw LaravelValidationException::withMessages(['kind' => ['This kind is taken.']]);
    });

    try {
        $response = $this->putJson("/martis/api/resources/rde-channel-models/{$trashed->id}/restore");

        // The panel reads a list of {field, message, code}, not Laravel's map.
        $response->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'kind')
            ->assertJsonPath('errors.0.message', 'This kind is taken.');
        expect(RdeChannelModel::withTrashed()->find($trashed->id)->trashed())->toBeTrue();
    } finally {
        RdeChannelModel::flushEventListeners();
    }
});

it('attributes the PostgreSQL composite key of the reported index to the field', function () {
    // The exact detail PostgreSQL prints for the reported index
    // (`channels_tenant_kind_unique (tenant_id, kind) WHERE deleted_at IS
    // NULL`). The suite runs on SQLite, so the driver's own message is
    // replayed here: `tenant_id` is no field of the resource and is
    // skipped, `kind` carries the error.
    $trashed = RdeChannelModel::create(['label' => 'Original', 'kind' => 'casa_sapo']);
    $trashed->delete();

    $pdo = new PDOException('SQLSTATE[23505]: Unique violation');
    $pdo->errorInfo = [
        '23505',
        7,
        "ERROR:  duplicate key value violates unique constraint \"rde_channels_tenant_kind_unique\"\nDETAIL:  Key (tenant_id, kind)=(1, casa_sapo) already exists.",
    ];

    RdeChannelModel::restoring(function () use ($pdo): void {
        throw new UniqueConstraintViolationException('pgsql', 'update "rde_channels" set "deleted_at" = ?', [], $pdo);
    });

    try {
        $response = $this->putJson("/martis/api/resources/rde-channel-models/{$trashed->id}/restore");

        $response->assertStatus(422)
            ->assertJsonPath('message', DatabaseErrorResponse::UNIQUE_MESSAGE);

        $errors = [];
        foreach ($response->json('errors') as $error) {
            $errors[$error['field']] = $error['message'];
        }

        expect($errors)->toBe(['kind' => 'The Kind has already been taken.'])
            ->and(RdeChannelModel::withTrashed()->find($trashed->id)->trashed())->toBeTrue();
    } finally {
        RdeChannelModel::flushEventListeners();
    }
});

it('calls beforeRestore() and afterRestore() around a successful restore', function () {
    $trashed = RdeChannelModel::create(['label' => 'Original', 'kind' => 'casa_sapo']);
    $trashed->delete();

    $this->putJson("/martis/api/resources/rde-hooked-channels/{$trashed->id}/restore")
        ->assertStatus(200);

    expect(RdeHookedChannelResource::$calls)->toBe([
        'before:'.$trashed->id,
        'after:'.$trashed->id,
    ]);
});

it('rolls the restore back when afterRestore() throws, and answers the generic 500', function () {
    config(['app.debug' => false]);

    $trashed = RdeChannelModel::create(['label' => 'Original', 'kind' => 'casa_sapo']);
    $trashed->delete();

    $response = $this->putJson("/martis/api/resources/rde-failing-after-channels/{$trashed->id}/restore");

    $response->assertStatus(500)
        ->assertJsonPath('message', __('martis::messages.error_restore'));
    expect($response->getContent())->not->toContain('hook exploded')->not->toContain('/var/www');

    // Without the transaction the row stayed restored while the endpoint failed.
    expect(RdeChannelModel::withTrashed()->find($trashed->id)->trashed())->toBeTrue();
});

it('rolls the restore back when a restored model listener throws', function () {
    config(['app.debug' => false]);

    $trashed = RdeChannelModel::create(['label' => 'Original', 'kind' => 'casa_sapo']);
    $trashed->delete();

    RdeChannelModel::restored(function (): void {
        throw new RuntimeException('listener exploded');
    });

    try {
        $this->putJson("/martis/api/resources/rde-channel-models/{$trashed->id}/restore")
            ->assertStatus(500)
            ->assertJsonPath('message', __('martis::messages.error_restore'));

        expect(RdeChannelModel::withTrashed()->find($trashed->id)->trashed())->toBeTrue();
    } finally {
        RdeChannelModel::flushEventListeners();
    }
});

it('does not report a restore cancelled by a restoring listener as a success', function () {
    $trashed = RdeChannelModel::create(['label' => 'Original', 'kind' => 'casa_sapo']);
    $trashed->delete();

    // Returning false cancels the restore without throwing: Eloquent's
    // restore() answers false and the row stays trashed.
    RdeChannelModel::restoring(fn (): bool => false);

    try {
        $response = $this->putJson("/martis/api/resources/rde-hooked-channels/{$trashed->id}/restore");

        $response->assertStatus(422)
            ->assertJsonPath('message', __('martis::messages.error_restore'));

        expect(RdeChannelModel::withTrashed()->find($trashed->id)->trashed())->toBeTrue()
            ->and(RdeHookedChannelResource::$calls)->toBe(['before:'.$trashed->id]);
    } finally {
        RdeChannelModel::flushEventListeners();
    }
});

it('lets an abort() in beforeRestore() keep its status instead of flattening it to a 500', function () {
    $trashed = RdeChannelModel::create(['label' => 'Original', 'kind' => 'casa_sapo']);
    $trashed->delete();

    $response = $this->putJson("/martis/api/resources/rde-aborting-channels/{$trashed->id}/restore");

    $response->assertStatus(403)
        ->assertJsonPath('message', 'Custom reason');
    expect(RdeChannelModel::withTrashed()->find($trashed->id)->trashed())->toBeTrue();
});

it('lets an abort() in a restoring listener keep its status instead of flattening it to a 500', function () {
    $trashed = RdeChannelModel::create(['label' => 'Original', 'kind' => 'casa_sapo']);
    $trashed->delete();

    RdeChannelModel::restoring(function (): void {
        abort(403, 'Custom reason');
    });

    try {
        $this->putJson("/martis/api/resources/rde-channel-models/{$trashed->id}/restore")
            ->assertStatus(403)
            ->assertJsonPath('message', 'Custom reason');

        expect(RdeChannelModel::withTrashed()->find($trashed->id)->trashed())->toBeTrue();
    } finally {
        RdeChannelModel::flushEventListeners();
    }
});

it('lets an HttpResponseException from a restoring listener answer as the caller built it', function () {
    $trashed = RdeChannelModel::create(['label' => 'Original', 'kind' => 'casa_sapo']);
    $trashed->delete();

    RdeChannelModel::restoring(function (): void {
        throw new HttpResponseException(response()->json(['custom' => 'payload'], 409));
    });

    try {
        $this->putJson("/martis/api/resources/rde-channel-models/{$trashed->id}/restore")
            ->assertStatus(409)
            ->assertExactJson(['custom' => 'payload']);

        expect(RdeChannelModel::withTrashed()->find($trashed->id)->trashed())->toBeTrue();
    } finally {
        RdeChannelModel::flushEventListeners();
    }
});
