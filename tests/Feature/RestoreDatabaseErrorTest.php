<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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

        $response->assertStatus(422)
            ->assertJsonPath('errors.kind.0', 'This kind is taken.');
        expect(RdeChannelModel::withTrashed()->find($trashed->id)->trashed())->toBeTrue();
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
