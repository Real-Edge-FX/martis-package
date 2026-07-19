<?php

use Illuminate\Support\Facades\Schema;
use Martis\Invitations\Invitation;
use Martis\Stubs\StubResolver;

// -----------------------------------------------------------------------------
// The Invitation Eloquent model (Task 3). No manager/behaviour ships yet
// (see Task 1: config block + martis-invite gate; Task 2: the portable
// invitations migration stub). This test provisions the `invitations`
// table the same way InvitationsMigrationTest.php does, via the published
// migration stub, so Invitation::create(...) has somewhere to write.
// -----------------------------------------------------------------------------

beforeEach(function () {
    Schema::dropIfExists('invitations');

    $migration = require StubResolver::path('create_invitations_table.php.stub');
    $migration->up();
});

afterEach(function () {
    Schema::dropIfExists('invitations');
});

it('casts metadata to array and exposes status helpers', function () {
    $inv = Invitation::create([
        'email' => 'a@b.com', 'token' => hash('sha256', 'x'), 'status' => Invitation::STATUS_PENDING,
        'metadata' => ['k' => 'v'], 'expires_at' => now()->subHour(),
    ]);

    expect($inv->metadata)->toBe(['k' => 'v']);
    expect($inv->isPending())->toBeTrue();
    expect($inv->isExpired())->toBeTrue();
});

it('is not pending for other statuses and not expired without expires_at', function () {
    $inv = Invitation::create([
        'email' => 'c@d.com', 'token' => hash('sha256', 'y'), 'status' => Invitation::STATUS_ACCEPTED,
    ]);

    expect($inv->isPending())->toBeFalse();
    expect($inv->isExpired())->toBeFalse();
});

it('is not expired when expires_at is in the future', function () {
    $inv = Invitation::create([
        'email' => 'e@f.com', 'token' => hash('sha256', 'z'), 'status' => Invitation::STATUS_PENDING,
        'expires_at' => now()->addHour(),
    ]);

    expect($inv->isExpired())->toBeFalse();
});

it('exposes the status constants', function () {
    expect(Invitation::STATUS_PENDING)->toBe('pending');
    expect(Invitation::STATUS_ACCEPTED)->toBe('accepted');
    expect(Invitation::STATUS_REVOKED)->toBe('revoked');
    expect(Invitation::STATUS_EXPIRED)->toBe('expired');
});

it('leaves rawToken as a non-persisted, in-memory-only property', function () {
    $inv = Invitation::create([
        'email' => 'g@h.com', 'token' => hash('sha256', 'w'), 'status' => Invitation::STATUS_PENDING,
    ]);

    expect($inv->rawToken)->toBeNull();

    $inv->rawToken = 'plain-text-token';
    expect($inv->rawToken)->toBe('plain-text-token');
    expect($inv->toArray())->not->toHaveKey('rawToken');
});
