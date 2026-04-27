<?php

declare(strict_types=1);

use Martis\Sso\SsoIdentity;
use Martis\Tests\TestCase;

uses(TestCase::class);

it('SsoIdentity is an immutable value object with the expected fields', function () {
    $id = new SsoIdentity(
        provider: 'azure',
        externalId: 'abc-123',
        email: 'user@example.com',
        name: 'John Doe',
        externalRoles: ['Admin', 'Sales'],
        raw: ['displayName' => 'John', 'mail' => 'user@example.com'],
        accessToken: 'eyJhbGciOi…',
    );

    expect($id->provider)->toBe('azure');
    expect($id->externalId)->toBe('abc-123');
    expect($id->email)->toBe('user@example.com');
    expect($id->name)->toBe('John Doe');
    expect($id->externalRoles)->toEqual(['Admin', 'Sales']);
    expect($id->raw['displayName'])->toBe('John');
    expect($id->accessToken)->toBe('eyJhbGciOi…');
});

it('SsoIdentity defaults are reasonable for minimal payloads', function () {
    $id = new SsoIdentity(
        provider: 'github',
        externalId: '42',
        email: null,
        name: null,
    );

    expect($id->externalRoles)->toBe([]);
    expect($id->raw)->toBe([]);
    expect($id->accessToken)->toBeNull();
});
