<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Martis\Invitations\Events\InvitationAccepted;
use Martis\Invitations\Events\InvitationCreated;
use Martis\Invitations\Events\InvitationRevoked;
use Martis\Invitations\InvitationManager;
use Martis\Models\ActionEvent;
use Martis\Stubs\StubResolver;

// -----------------------------------------------------------------------------
// Task 7 — events + RecordInvitation audit listener. invite()/accept()/revoke()
// each fire an event (InvitationCreated / InvitationAccepted / InvitationRevoked)
// that RecordInvitation turns into one martis_action_events row, gated by
// config('martis.audit.invitations') + Schema::hasTable('martis_action_events').
//
// Provisioning mirrors InvitationManagerTest.php (users + invitations via the
// published migration stub) and RecordRoleChangeTest.php (martis_action_events
// ad hoc schema, string-typed morph id columns per the real create stub).
// -----------------------------------------------------------------------------

beforeEach(function () {
    config(['auth.providers.users.model' => User::class]);

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    Schema::dropIfExists('invitations');
    $migration = require StubResolver::path('create_invitations_table.php.stub');
    $migration->up();

    Schema::dropIfExists('martis_action_events');
    Schema::create('martis_action_events', function ($t) {
        $t->id();
        $t->uuid('batch_id');
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('name');
        $t->string('actionable_type')->nullable();
        $t->string('actionable_id')->nullable();
        $t->string('target_type')->nullable();
        $t->string('target_id')->nullable();
        $t->string('model_type')->nullable();
        $t->string('model_id')->nullable();
        $t->json('fields')->nullable();
        $t->string('status');
        $t->text('exception')->nullable();
        $t->json('original')->nullable();
        $t->json('changes')->nullable();
        $t->timestamps();
    });

    config()->set('martis.audit.invitations', true);
});

afterEach(function () {
    Schema::dropIfExists('invitations');
    Schema::dropIfExists('martis_action_events');
});

// -----------------------------------------------------------------------------
// Events dispatched
// -----------------------------------------------------------------------------

it('dispatches InvitationCreated from invite()', function () {
    Event::fake([InvitationCreated::class]);

    $inv = app(InvitationManager::class)->invite('created@ex.com', 'editor');

    Event::assertDispatched(InvitationCreated::class, fn ($e) => $e->invitation->is($inv));
});

it('dispatches InvitationAccepted from accept()', function () {
    Event::fake([InvitationAccepted::class]);

    $mgr = app(InvitationManager::class);
    $inv = $mgr->invite('accept-evt@ex.com');
    $user = $mgr->accept($inv->rawToken, ['name' => 'Ann', 'password' => 'password123', 'password_confirmation' => 'password123']);

    Event::assertDispatched(
        InvitationAccepted::class,
        fn ($e) => $e->invitation->id === $inv->id && $e->user->getAuthIdentifier() === $user->getAuthIdentifier(),
    );
});

it('dispatches InvitationRevoked from revoke()', function () {
    Event::fake([InvitationRevoked::class]);

    $mgr = app(InvitationManager::class);
    $inv = $mgr->invite('revoke-evt@ex.com');
    $mgr->revoke($inv);

    Event::assertDispatched(InvitationRevoked::class, fn ($e) => $e->invitation->is($inv));
});

// -----------------------------------------------------------------------------
// Audit rows — audit ON (default)
// -----------------------------------------------------------------------------

it('invite() writes one invitation.created ActionEvent row', function () {
    $inv = app(InvitationManager::class)->invite('audit-created@ex.com', 'editor');

    expect(ActionEvent::query()->where('name', 'invitation.created')->count())->toBe(1);

    $row = ActionEvent::query()->where('name', 'invitation.created')->first();
    expect($row->target_id)->toBe('audit-created@ex.com')
        ->and($row->fields)->toBe(['role' => 'editor', 'invitation_id' => $inv->id]);
});

it('accept() writes one invitation.accepted ActionEvent row', function () {
    $mgr = app(InvitationManager::class);
    $inv = $mgr->invite('audit-accept@ex.com', 'editor');
    $mgr->accept($inv->rawToken, ['name' => 'Ann', 'password' => 'password123', 'password_confirmation' => 'password123']);

    expect(ActionEvent::query()->where('name', 'invitation.accepted')->count())->toBe(1);

    $row = ActionEvent::query()->where('name', 'invitation.accepted')->first();
    expect($row->target_id)->toBe('audit-accept@ex.com')
        ->and($row->fields)->toBe(['role' => 'editor', 'invitation_id' => $inv->id]);
});

it('revoke() writes one invitation.revoked ActionEvent row', function () {
    $mgr = app(InvitationManager::class);
    $inv = $mgr->invite('audit-revoke@ex.com');
    $mgr->revoke($inv);

    expect(ActionEvent::query()->where('name', 'invitation.revoked')->count())->toBe(1);

    $row = ActionEvent::query()->where('name', 'invitation.revoked')->first();
    expect($row->target_id)->toBe('audit-revoke@ex.com')
        ->and($row->fields)->toBe(['role' => null, 'invitation_id' => $inv->id]);
});

// -----------------------------------------------------------------------------
// Audit OFF — config('martis.audit.invitations', false)
// -----------------------------------------------------------------------------

it('invite() writes no row when martis.audit.invitations is false', function () {
    config(['martis.audit.invitations' => false]);

    app(InvitationManager::class)->invite('off-created@ex.com');

    expect(ActionEvent::query()->count())->toBe(0);
});

it('accept() writes no row when martis.audit.invitations is false', function () {
    config(['martis.audit.invitations' => false]);

    $mgr = app(InvitationManager::class);
    $inv = $mgr->invite('off-accept@ex.com');
    $mgr->accept($inv->rawToken, ['name' => 'Ann', 'password' => 'password123', 'password_confirmation' => 'password123']);

    expect(ActionEvent::query()->count())->toBe(0);
});

it('revoke() writes no row when martis.audit.invitations is false', function () {
    config(['martis.audit.invitations' => false]);

    $mgr = app(InvitationManager::class);
    $inv = $mgr->invite('off-revoke@ex.com');
    $mgr->revoke($inv);

    expect(ActionEvent::query()->count())->toBe(0);
});
