<?php

declare(strict_types=1);

use Martis\Sso\SsoAdoptionRefusedException;
use Martis\Sso\SsoException;
use Martis\Sso\SsoLogRedactor;

it('masks credential values written as query pairs', function (string $key) {
    $message = "Client error: `POST https://idp.example/token?{$key}=SECRET-VALUE-123&other=keep` resulted in a 400";

    $redacted = SsoLogRedactor::redact($message);

    expect($redacted)->not->toContain('SECRET-VALUE-123')
        ->and($redacted)->toContain('other=keep')
        ->and($redacted)->toContain("{$key}=[redacted]");
})->with(['code', 'state', 'token', 'id_token', 'access_token', 'refresh_token', 'client_secret']);

it('masks credential values written as JSON pairs', function (string $key) {
    $body = '{"error":"invalid_grant","'.$key.'":"SECRET-VALUE-123","hint":"keep"}';

    $redacted = SsoLogRedactor::redact("Server error: response: {$body}");

    expect($redacted)->not->toContain('SECRET-VALUE-123')
        ->and($redacted)->toContain('"hint":"keep"')
        ->and($redacted)->toContain('"'.$key.'":"[redacted]"');
})->with(['code', 'state', 'token', 'id_token', 'access_token', 'refresh_token', 'client_secret']);

it('masks JSON pairs with spacing and with escaped quotes', function () {
    $spaced = SsoLogRedactor::redact('{"access_token" :  "abc.def.ghi", "x": 1}');
    $escaped = SsoLogRedactor::redact('body: {\"refresh_token\":\"abc.def.ghi\"}');

    expect($spaced)->not->toContain('abc.def.ghi')
        ->and($escaped)->not->toContain('abc.def.ghi');
});

it('masks a bearer credential', function () {
    expect(SsoLogRedactor::redact('Authorization: Bearer eyJhbGciOi.payload.sig failed'))
        ->not->toContain('eyJhbGciOi');
});

it('does not treat a longer key that merely ends with a credential name as one', function () {
    $message = 'error_code=E42 and passcode=1234';

    expect(SsoLogRedactor::redact($message))->toBe('error_code=E42 and passcode=1234');
});

it('leaves a message with nothing to mask untouched', function () {
    expect(SsoLogRedactor::redact('Connection timed out after 30 seconds'))->toBe('Connection timed out after 30 seconds');
});

it('SsoAdoptionRefusedException is an SsoException that keeps its provider and email', function () {
    $e = new SsoAdoptionRefusedException('azure', 'a@example.com');

    expect($e)->toBeInstanceOf(SsoException::class)
        ->and($e->reason)->toBe(SsoException::REASON_ACCOUNT_UNVERIFIED)
        ->and($e->messageKey)->toBe('martis::messages.sso_account_unverified')
        ->and($e->provider)->toBe('azure')
        ->and($e->email)->toBe('a@example.com');
});

it('SsoException carries a reason and an optional message key', function () {
    $e = new SsoException('boom', 'custom_reason', 'martis::messages.sso_no_role_match');
    $bare = new SsoException('boom', 'custom_reason');

    expect($e->reason)->toBe('custom_reason')
        ->and($e->messageKey)->toBe('martis::messages.sso_no_role_match')
        ->and($bare->messageKey)->toBeNull()
        ->and($e->getMessage())->toBe('boom');
});
