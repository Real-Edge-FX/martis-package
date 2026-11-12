<?php

declare(strict_types=1);

use Martis\Sso\SignedToken\PasetoV4Public;
use Martis\Sso\SignedToken\Reason;
use Martis\Sso\SsoException;

/**
 * The PASETO v4.public codec against the official test vectors
 * (paseto-standard/test-vectors, v4.json: the 4-S-* cases sign and verify, the
 * 4-F-* cases must be refused) and against the shapes the spec says to reject.
 */

/** @return list<array<string, mixed>> */
function pasetoVectors(string $prefix): array
{
    $file = json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/Paseto/v4-public-vectors.json'), true, 16, JSON_THROW_ON_ERROR);

    return array_values(array_filter(
        $file['tests'],
        static fn (array $vector): bool => str_starts_with($vector['name'], $prefix),
    ));
}

function pasetoOpens(string $token, string $publicKey, string $implicit = ''): bool
{
    try {
        return PasetoV4Public::verify(PasetoV4Public::parse($token), $publicKey, $implicit);
    } catch (SsoException $e) {
        expect($e->reason)->toBe(Reason::MALFORMED);

        return false;
    }
}

function pasetoKeypair(): array
{
    $pair = sodium_crypto_sign_keypair();

    return [sodium_crypto_sign_secretkey($pair), sodium_crypto_sign_publickey($pair)];
}

function b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

it('encodes PAE as the spec defines it', function () {
    expect(bin2hex(PasetoV4Public::pae([])))->toBe('0000000000000000');
    expect(bin2hex(PasetoV4Public::pae([''])))->toBe('01000000000000000000000000000000');
    expect(PasetoV4Public::pae(['test']))->toBe("\x01\x00\x00\x00\x00\x00\x00\x00\x04\x00\x00\x00\x00\x00\x00\x00test");
});

it('signs exactly the official 4-S vectors (Ed25519 is deterministic)', function (array $vector) {
    $token = PasetoV4Public::sign(
        $vector['payload'],
        hex2bin($vector['secret-key']),
        $vector['footer'],
        $vector['implicit-assertion'],
    );

    expect($token)->toBe($vector['token']);
})->with(fn () => array_map(fn (array $v) => [$v], pasetoVectors('4-S')));

it('verifies the official 4-S vectors and returns payload and footer', function (array $vector) {
    $parsed = PasetoV4Public::parse($vector['token']);

    expect(PasetoV4Public::verify($parsed, hex2bin($vector['public-key']), $vector['implicit-assertion']))->toBeTrue();
    expect($parsed->message)->toBe($vector['payload']);
    expect($parsed->footer)->toBe($vector['footer']);
})->with(fn () => array_map(fn (array $v) => [$v], pasetoVectors('4-S')));

it('refuses every official 4-F vector', function (array $vector) {
    // The 4-F cases are tokens that must never open: other versions, other
    // purposes (v4.local), and a v4.public token that no key of the suite signed.
    $publicKey = hex2bin(pasetoVectors('4-S')[0]['public-key']);

    expect(pasetoOpens($vector['token'], $publicKey, $vector['implicit-assertion']))->toBeFalse();
})->with(fn () => array_map(fn (array $v) => [$v], pasetoVectors('4-F')));

it('refuses a 4-S token with a different implicit assertion or a tampered footer', function () {
    [$s3] = array_slice(pasetoVectors('4-S'), 2, 1);
    $publicKey = hex2bin($s3['public-key']);

    expect(pasetoOpens($s3['token'], $publicKey, $s3['implicit-assertion']))->toBeTrue();
    expect(pasetoOpens($s3['token'], $publicKey, ''))->toBeFalse();
    expect(pasetoOpens($s3['token'], $publicKey, '{"test-vector":"4-S-2"}'))->toBeFalse();

    [$token, $footer] = explode('.', substr($s3['token'], strlen('v4.public.')), 2);
    $forgedFooter = b64url('{"kid":"other"}');
    expect(pasetoOpens('v4.public.'.$token.'.'.$forgedFooter, $publicKey, $s3['implicit-assertion']))->toBeFalse();
    expect($footer)->not->toBe($forgedFooter);
});

it('refuses a token signed by another key and a tampered payload', function () {
    [$secret, $public] = pasetoKeypair();
    [, $otherPublic] = pasetoKeypair();
    $token = PasetoV4Public::sign('{"a":1}', $secret);

    expect(pasetoOpens($token, $public))->toBeTrue();
    expect(pasetoOpens($token, $otherPublic))->toBeFalse();

    $raw = sodium_base642bin(substr($token, strlen('v4.public.')), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $raw[2] = $raw[2] ^ "\x01";
    expect(pasetoOpens('v4.public.'.b64url($raw), $public))->toBeFalse();
});

it('rejects a token that is not well formed, with the malformed reason', function (string $token) {
    expect(fn () => PasetoV4Public::parse($token))
        ->toThrow(fn (SsoException $e) => expect($e->reason)->toBe(Reason::MALFORMED));
})->with([
    'empty' => [''],
    'wrong version' => ['v3.public.'.'AAAA'],
    'wrong purpose' => ['v4.local.'.'AAAA'],
    'upper case prefix' => ['V4.PUBLIC.AAAA'],
    'no payload' => ['v4.public.'],
    'payload shorter than a signature' => ['v4.public.'.'AAAA'],
    'bad alphabet (+ and /)' => ['v4.public.'.'+/+/+/'],
    'padding' => ['v4.public.'.'AAAA=='],
    'whitespace' => ["v4.public.AAAA\n"],
    'empty footer after the dot' => ['v4.public.AAAA.'],
    'trailing data (third segment)' => ['v4.public.AAAA.AAAA.AAAA'],
    'footer with a bad alphabet' => ['v4.public.AAAA.+++'],
]);

it('rejects non canonical base64url (non zero trailing bits)', function () {
    [$secret, $public] = pasetoKeypair();

    // A payload whose raw length (message + 64) leaves two trailing bits in
    // the last base64 character, which a strict decoder must see as zero.
    $message = '{"x":"';
    while ((strlen($message) + 2 + 64) % 3 !== 2) {
        $message .= 'a';
    }
    $token = PasetoV4Public::sign($message.'"}', $secret);
    expect(pasetoOpens($token, $public))->toBeTrue();

    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $last = $token[strlen($token) - 1];
    $index = strpos($alphabet, $last);
    expect($index % 4)->toBe(0);
    $variant = substr($token, 0, -1).$alphabet[$index + 1];

    expect(fn () => PasetoV4Public::parse($variant))
        ->toThrow(fn (SsoException $e) => expect($e->reason)->toBe(Reason::MALFORMED));
});

it('rejects an oversized token before decoding it', function () {
    expect(fn () => PasetoV4Public::parse('v4.public.'.str_repeat('A', 20000)))
        ->toThrow(fn (SsoException $e) => expect($e->reason)->toBe(Reason::MALFORMED));
});

it('round trips a token with and without a footer', function () {
    [$secret, $public] = pasetoKeypair();

    foreach (['', '{"kid":"abc"}'] as $footer) {
        $token = PasetoV4Public::sign('{"hello":"world"}', $secret, $footer);
        $parsed = PasetoV4Public::parse($token);

        expect(PasetoV4Public::verify($parsed, $public))->toBeTrue();
        expect($parsed->message)->toBe('{"hello":"world"}');
        expect($parsed->footer)->toBe($footer);
        expect(substr_count($token, '.'))->toBe($footer === '' ? 2 : 3);
    }
});

it('refuses keys of the wrong length when signing or verifying', function () {
    expect(fn () => PasetoV4Public::sign('{}', 'short'))->toThrow(InvalidArgumentException::class);
    [$secret] = pasetoKeypair();
    $parsed = PasetoV4Public::parse(PasetoV4Public::sign('{}', $secret));
    expect(fn () => PasetoV4Public::verify($parsed, 'short'))->toThrow(InvalidArgumentException::class);
});

// -----------------------------------------------------------------------------
// A token and a key never reach a stack trace (#[\SensitiveParameter], v2.22.0)
// -----------------------------------------------------------------------------

/** The trace of what $attempt throws, with the trace arguments switched on (the development default). */
function pasetoTraceOf(Closure $attempt): string
{
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        $attempt();
    } catch (Throwable $e) {
        return $e->getTraceAsString();
    } finally {
        ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
    }

    throw new RuntimeException('nothing was thrown');
}

it('keeps the token out of the trace of a refused token', function (string $token) {
    $trace = pasetoTraceOf(fn () => PasetoV4Public::parse($token));

    // Only the frames of the codec: the test's own frame carries the dataset value.
    $frames = implode("\n", array_filter(explode("\n", $trace), fn (string $line) => str_contains($line, 'PasetoV4Public::parse')));

    expect($frames)->not->toContain(substr($token, 0, 15))->and($frames)->toContain('SensitiveParameterValue');
})->with([
    'not a v4.public token' => ['v3.public.SECRET-TOKEN-BODY-0123456789'],
    'a bad base64 body' => ['v4.public.SECRET*TOKEN*BODY*0123456789'],
]);

it('keeps the secret key and the message out of the trace of a bad key', function () {
    $secret = 'SECRET-KEY-OF-THE-WRONG-LENGTH';
    $message = '{"state":"SECRET-STATE-IN-THE-MESSAGE"}';

    $trace = pasetoTraceOf(fn () => PasetoV4Public::sign($message, $secret));

    // A trace cuts a string argument to its first 15 characters: that prefix is what must not appear.
    expect($trace)->not->toContain('SECRET-KEY-OF-T')->not->toContain('{"state":"SECRE');
});
