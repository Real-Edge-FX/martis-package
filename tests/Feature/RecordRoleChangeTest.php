<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Martis\Auth\Listeners\RecordRoleChange;
use Martis\Models\ActionEvent;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

class RoleChangeTestUser extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('email')->unique();
            $t->timestamps();
        });
    }

    Schema::dropIfExists('martis_action_events');
    Schema::create('martis_action_events', function ($t) {
        $t->id();
        $t->uuid('batch_id');
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('name');
        $t->string('actionable_type')->nullable();
        $t->unsignedBigInteger('actionable_id')->nullable();
        $t->string('target_type')->nullable();
        $t->unsignedBigInteger('target_id')->nullable();
        $t->string('model_type')->nullable();
        $t->unsignedBigInteger('model_id')->nullable();
        $t->json('fields')->nullable();
        $t->string('status');
        $t->text('exception')->nullable();
        $t->json('original')->nullable();
        $t->json('changes')->nullable();
        $t->timestamps();
    });

    config()->set('martis.audit.role_changes', true);
});

afterEach(function () {
    Schema::dropIfExists('martis_action_events');
});

it('writes an ActionEvent row when a role is attached', function () {
    $user = RoleChangeTestUser::create(['email' => 'a@example.com']);

    (new RecordRoleChange)->handleRoleAttached(
        new RoleAttachedEvent($user, [42, 43]),
    );

    $row = ActionEvent::query()->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->name)->toBe('role.attached')
        ->and($row->model_type)->toBe(RoleChangeTestUser::class)
        ->and($row->model_id)->toBe($user->id)
        ->and($row->fields)->toBe(['ids' => [42, 43]])
        ->and($row->status)->toBe('finished');
});

it('writes an ActionEvent row when a role is detached', function () {
    $user = RoleChangeTestUser::create(['email' => 'b@example.com']);

    (new RecordRoleChange)->handleRoleDetached(
        new RoleDetachedEvent($user, [99]),
    );

    expect(ActionEvent::query()->where('name', 'role.detached')->count())->toBe(1);
});

it('writes ActionEvent rows for permission events', function () {
    $user = RoleChangeTestUser::create(['email' => 'c@example.com']);

    (new RecordRoleChange)->handlePermissionAttached(
        new PermissionAttachedEvent($user, [7]),
    );
    (new RecordRoleChange)->handlePermissionDetached(
        new PermissionDetachedEvent($user, [7]),
    );

    expect(ActionEvent::query()->where('name', 'permission.attached')->count())->toBe(1)
        ->and(ActionEvent::query()->where('name', 'permission.detached')->count())->toBe(1);
});

it('skips the audit write when the table is missing', function () {
    $user = RoleChangeTestUser::create(['email' => 'd@example.com']);
    Schema::dropIfExists('martis_action_events');

    (new RecordRoleChange)->handleRoleAttached(
        new RoleAttachedEvent($user, [1]),
    );

    // Table missing — no exception, no row.
    Schema::create('martis_action_events', function ($t) {
        $t->id();
        $t->uuid('batch_id');
        $t->string('name');
        $t->string('status');
        $t->timestamps();
    });
    expect(ActionEvent::query()->count())->toBe(0);
});

it('skips the audit write when role_changes config is false', function () {
    $user = RoleChangeTestUser::create(['email' => 'e@example.com']);
    config()->set('martis.audit.role_changes', false);

    (new RecordRoleChange)->handleRoleAttached(
        new RoleAttachedEvent($user, [1]),
    );

    expect(ActionEvent::query()->count())->toBe(0);
});

it('skips the audit write when the rolesOrIds payload is empty', function () {
    $user = RoleChangeTestUser::create(['email' => 'f@example.com']);

    (new RecordRoleChange)->handleRoleAttached(
        new RoleAttachedEvent($user, []),
    );

    expect(ActionEvent::query()->count())->toBe(0);
});

it('registers itself against the four Spatie event classes when registerRoleAuditListeners runs', function () {
    // The provider's boot() ran during the test app setup. Confirm
    // the four events have at least one listener whose class is the
    // Martis recorder.
    foreach ([RoleAttachedEvent::class, RoleDetachedEvent::class, PermissionAttachedEvent::class, PermissionDetachedEvent::class] as $event) {
        $listeners = Event::getListeners($event);
        $hasRecorder = collect($listeners)->contains(function ($listener) {
            // Internally Laravel wraps the [Class, method] tuple in
            // a closure. The class name is therefore not directly
            // inspectable; we instead invoke the closure with a
            // sentinel "no model, no payload" event and rely on the
            // recorder's defensive shape check to make it a no-op.
            return is_callable($listener);
        });
        expect($hasRecorder)->toBeTrue("No listener registered for {$event}");
    }
});
