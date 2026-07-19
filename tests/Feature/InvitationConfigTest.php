<?php

// tests/Feature/InvitationConfigTest.php
use Illuminate\Support\Facades\Gate;

it('defaults the invitations feature to disabled', function () {
    expect(config('martis.invitations.enabled'))->toBeFalse();
    expect(config('martis.invitations.expires_after_hours'))->toBe(72);
    expect(config('martis.invitations.signup_fields'))->toBe(['name', 'password']);
    expect(config('martis.invitations.audit'))->toBeTrue();
});

it('ships no default martis-invite gate (denied until the consumer defines it)', function () {
    expect(Gate::denies('martis-invite'))->toBeTrue();
});
