<?php

declare(strict_types=1);

namespace Martis\Sso\SignedToken;

use InvalidArgumentException;
use Martis\Sso\Providers\SignedTokenProvider;
use Martis\Sso\SsoException;
use RuntimeException;
use SodiumException;

/**
 * PASETO v4.public (Ed25519) on ext-sodium alone, as the PASETO specification
 * defines it (Version4.md, "Sign" and "Verify", with the PAE of Common.md).
 *
 *     v4.public.<base64url(message || signature)>[.<base64url(footer)>]
 *
 * The signature covers PAE("v4.public.", message, footer, implicit assertion),
 * so the footer is authenticated along with the message. There is no algorithm
 * header and no negotiation: the prefix is the only accepted one. The codec
 * knows nothing of claims, keys in config or time; {@see SignedTokenProvider}
 * and {@see TokenIssuer} sit on top of it. It is checked against the official
 * test vectors (tests/Fixtures/Paseto).
 *
 * Parsing is strict: the token is split on its dots, base64url is
 * canonical (the unpadded alphabet only, and re-encoding the decoded bytes
 * gives the same text, so a non zero trailing bit is refused), a footer is
 * never empty when its dot is there, and a third segment is refused.
 */
final class PasetoV4Public
{
    public const HEADER = 'v4.public.';

    /** A token this long is not one of ours: refused before it is decoded. */
    public const MAX_TOKEN_LENGTH = 8192;

    private const SIGNATURE_BYTES = 64;

    /**
     * Pre-Authentication Encoding: LE64(count) then, for each piece,
     * LE64(length) and the piece.
     *
     * @param  list<string>  $pieces
     */
    public static function pae(array $pieces): string
    {
        $out = pack('P', count($pieces));

        foreach ($pieces as $piece) {
            $out .= pack('P', strlen($piece) & PHP_INT_MAX).$piece;
        }

        return $out;
    }

    /**
     * @param  string  $secretKey  the 64 raw bytes of an Ed25519 secret key (libsodium form: seed then public key)
     */
    public static function sign(#[\SensitiveParameter] string $message, #[\SensitiveParameter] string $secretKey, string $footer = '', string $implicit = ''): string
    {
        self::ensureAvailable();

        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException('An Ed25519 secret key is '.SODIUM_CRYPTO_SIGN_SECRETKEYBYTES.' bytes.');
        }

        $signature = sodium_crypto_sign_detached(self::pae([self::HEADER, $message, $footer, $implicit]), $secretKey);

        $token = self::HEADER.sodium_bin2base64($message.$signature, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        return $footer === ''
            ? $token
            : $token.'.'.sodium_bin2base64($footer, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /**
     * Split a token into message, signature and footer. Does not verify it.
     *
     * @throws SsoException reason `malformed`
     */
    public static function parse(#[\SensitiveParameter] string $token): ParsedToken
    {
        self::ensureAvailable();

        if (strlen($token) > self::MAX_TOKEN_LENGTH) {
            throw self::malformed('the token is too long');
        }

        if (! str_starts_with($token, self::HEADER)) {
            throw self::malformed('the token is not a v4.public token');
        }

        $segments = explode('.', substr($token, strlen(self::HEADER)));

        if (count($segments) > 2) {
            throw self::malformed('the token has trailing data');
        }

        $raw = self::decode($segments[0]);

        if (strlen($raw) < self::SIGNATURE_BYTES) {
            throw self::malformed('the token is shorter than a signature');
        }

        $footer = '';
        if (isset($segments[1])) {
            if ($segments[1] === '') {
                throw self::malformed('the footer is empty');
            }

            $footer = self::decode($segments[1]);
        }

        return new ParsedToken(
            message: substr($raw, 0, -self::SIGNATURE_BYTES),
            signature: substr($raw, -self::SIGNATURE_BYTES),
            footer: $footer,
        );
    }

    /**
     * Whether `$publicKey` (32 raw bytes) signed the token. Constant time in
     * the signature; a wrong signature is false, never an exception.
     */
    public static function verify(ParsedToken $token, string $publicKey, string $implicit = ''): bool
    {
        self::ensureAvailable();

        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('An Ed25519 public key is '.SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES.' bytes.');
        }

        $signature = $token->signature;
        if ($signature === '') {
            return false;
        }

        return sodium_crypto_sign_verify_detached(
            $signature,
            self::pae([self::HEADER, $token->message, $token->footer, $implicit]),
            $publicKey,
        );
    }

    /**
     * The `kid` of a public key: the first 16 hex characters of its SHA-256.
     */
    public static function keyId(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, 16);
    }

    /**
     * ext-sodium is a `suggest`, not a requirement: the signed-token driver
     * says what to do when the extension is not loaded.
     */
    public static function ensureAvailable(): void
    {
        if (! extension_loaded('sodium') || ! function_exists('sodium_crypto_sign_detached')) {
            throw new RuntimeException(
                "The signed-token SSO driver needs PHP's sodium extension (ext-sodium), which is not loaded.\n\n".
                "Enable it (it ships with PHP 7.2+; on Debian/Ubuntu: apt install php-sodium) and restart PHP.\n".
                'See docs/sso.md, "Signed token between two Martis apps".',
            );
        }
    }

    /**
     * Strict, canonical, unpadded base64url.
     *
     * @throws SsoException reason `malformed`
     */
    private static function decode(#[\SensitiveParameter] string $encoded): string
    {
        if ($encoded === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $encoded) !== 1 || strlen($encoded) % 4 === 1) {
            throw self::malformed('a segment is not base64url');
        }

        try {
            $raw = sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (SodiumException) {
            throw self::malformed('a segment is not base64url');
        }

        if (! hash_equals($encoded, sodium_bin2base64($raw, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING))) {
            throw self::malformed('a segment is not canonical base64url');
        }

        return $raw;
    }

    private static function malformed(string $why): SsoException
    {
        return new SsoException('Signed token refused: '.$why.'.', Reason::MALFORMED);
    }
}
