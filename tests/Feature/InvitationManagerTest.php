<?php

use Illuminate\Support\Facades\Schema;
use Martis\Invitations\Invitation;
use Martis\Invitations\InvitationManager;
use Martis\Stubs\StubResolver;

// -----------------------------------------------------------------------------
// InvitationManager token core (Task 4). Only invite() + findByRawToken()
// ship here — accept()/resend()/revoke() are later tasks. This test
// provisions the `invitations` table the same way InvitationModelTest.php
// does, via the published migration stub, so invite() has somewhere to
// persist the invitation.
// -----------------------------------------------------------------------------

beforeEach(function () {
    Schema::dropIfExists('invitations');

    $migration = require StubResolver::path('create_invitations_table.php.stub');
    $migration->up();
});

afterEach(function () {
    Schema::dropIfExists('invitations');
});

it('invite() stores only the hashed token and exposes the raw once', function () {
    $inv = app(InvitationManager::class)->invite('new@ex.com', 'editor', ['k' => 'v']);
    expect($inv->status)->toBe(Invitation::STATUS_PENDING);
    expect($inv->role)->toBe('editor');
    expect(strlen($inv->rawToken))->toBeGreaterThan(32);            // raw returned in-memory
    expect($inv->token)->toBe(hash('sha256', $inv->rawToken));      // only the hash persisted
    expect(Invitation::where('token', $inv->rawToken)->exists())->toBeFalse(); // raw never in DB
    expect($inv->expires_at)->not->toBeNull();
});

it('findByRawToken() resolves by hash and returns null for an unknown token', function () {
    $mgr = app(InvitationManager::class);
    $inv = $mgr->invite('new2@ex.com');
    expect($mgr->findByRawToken($inv->rawToken)?->id)->toBe($inv->id);
    expect($mgr->findByRawToken('not-a-real-token'))->toBeNull();
});
