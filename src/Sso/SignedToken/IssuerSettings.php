<?php

declare(strict_types=1);

namespace Martis\Sso\SignedToken;

use InvalidArgumentException;

/**
 * `martis.auth.sso.issuer`, validated: the panel that signs other panels in.
 * A value that is set but unusable throws, naming its key; the secret key
 * never appears in a message.
 */
final class IssuerSettings
{
    public const KEY = 'martis.auth.sso.issuer';

    /** `{audience}` of the issue route: also the shape of an audience name. */
    public const AUDIENCE_PATTERN = '[A-Za-z0-9][A-Za-z0-9._-]{0,63}';

    /**
     * The claims a claims class cannot set: they bind the token to its
     * issuer, audience, user, time, browser session and one use, the
     * `email_verified` the issuer states from the user model (v2.21.0) and the
     * environment claim (always, whether or not `environment` is set).
     */
    public const RESERVED_CLAIMS = ['iss', 'aud', 'sub', 'iat', 'exp', 'jti', 'state', 'email_verified', self::ENVIRONMENT_CLAIM];

    /**
     * The claim that carries the issuer's `environment`, on both sides. Not
     * configurable since v2.22.0 (see {@see ConfigReader::removed()}).
     */
    public const ENVIRONMENT_CLAIM = 'env';

    /** Longer than this is not a one-time link any more. */
    public const MAX_TTL_SECONDS = 600;

    /**
     * @param  array<string, string>  $audiences  audience name => callback URL
     * @param  array<string, string|null>  $abilities  audience name => the gate ability a user needs for it (null: any panel user)
     */
    private function __construct(
        public readonly string $issuer,
        #[\SensitiveParameter] public readonly string $secretKey,
        public readonly string $publicKey,
        public readonly int $ttlSeconds,
        public readonly ?string $environment,
        public readonly string $roleClaim,
        public readonly mixed $claims,
        public readonly array $audiences,
        public readonly array $abilities = [],
    ) {}

    /** Whether the issuer is switched on (the only key the 404 of the route needs). */
    public static function enabled(): bool
    {
        $block = config(self::KEY);

        return is_array($block) && ConfigReader::bool($block['enabled'] ?? false, self::KEY.'.enabled');
    }

    /** Whether `$audience` is a configured one (no other validation: the route answers 404 for the rest). */
    public static function hasAudience(string $audience): bool
    {
        $audiences = config(self::KEY.'.audiences');

        return is_array($audiences) && array_key_exists($audience, $audiences);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromConfig(): self
    {
        $key = self::KEY;
        $block = config($key);

        if (! is_array($block)) {
            throw new InvalidArgumentException("The [{$key}] config value must be an array, got ".get_debug_type($block).'.');
        }

        $secret = ConfigReader::base64Key(
            $block['private_key'] ?? null,
            "{$key}.private_key",
            SODIUM_CRYPTO_SIGN_SECRETKEYBYTES,
            'an Ed25519 secret key (generate one with: php artisan martis:sso:keys)',
        );

        // libsodium keeps the public key in the second half of the secret key:
        // a half that does not belong to the seed would sign tokens no key verifies.
        [$seed, $embeddedPublic] = str_split($secret, SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
        if (! hash_equals($public, $embeddedPublic)) {
            throw new InvalidArgumentException("The [{$key}.private_key] config value is not a consistent Ed25519 secret key (its public half does not match its seed).");
        }

        $audiences = self::audiences($block['audiences'] ?? []);

        ConfigReader::removed($block['environment_claim'] ?? null, "{$key}.environment_claim", 'the claim that carries the environment is always `'.self::ENVIRONMENT_CLAIM.'`. Remove the key.');

        return new self(
            issuer: ConfigReader::string($block['issuer'] ?? null, "{$key}.issuer"),
            secretKey: $secret,
            publicKey: $public,
            ttlSeconds: ConfigReader::int($block['ttl_seconds'] ?? null, "{$key}.ttl_seconds", 60, 1, self::MAX_TTL_SECONDS),
            environment: ConfigReader::optionalString($block['environment'] ?? null, "{$key}.environment"),
            roleClaim: ConfigReader::stringOr($block['role_claim'] ?? null, "{$key}.role_claim", 'roles'),
            claims: $block['claims'] ?? null,
            audiences: $audiences['urls'],
            abilities: $audiences['abilities'],
        );
    }

    /**
     * @return array{urls: array<string, string>, abilities: array<string, string|null>}
     */
    private static function audiences(mixed $audiences): array
    {
        $key = self::KEY.'.audiences';

        if (! is_array($audiences)) {
            throw new InvalidArgumentException("The [{$key}] config value must be an array of audience name => ['callback_url' => ...], got ".get_debug_type($audiences).'.');
        }

        $out = [];
        $abilities = [];
        foreach ($audiences as $name => $audience) {
            $name = (string) $name;

            // `callback` and `redirect` would shadow the provider routes of
            // /sso/{provider}/callback and /sso/{provider}/redirect for a provider named "issue".
            if (preg_match('/\A'.self::AUDIENCE_PATTERN.'\z/', $name) !== 1 || in_array($name, ['callback', 'redirect'], true)) {
                throw new InvalidArgumentException("The [{$key}] audience name [{$name}] must be 1 to 64 letters, digits, dots, dashes or underscores, starting with a letter or digit, and not be \"callback\" or \"redirect\".");
            }

            if (! is_array($audience)) {
                throw new InvalidArgumentException("The [{$key}.{$name}] config value must be an array with a callback_url, got ".get_debug_type($audience).'.');
            }

            $out[$name] = Urls::assertUsable($audience['callback_url'] ?? null, "{$key}.{$name}.callback_url");
            $abilities[$name] = ConfigReader::optionalString($audience['ability'] ?? null, "{$key}.{$name}.ability");
        }

        return ['urls' => $out, 'abilities' => $abilities];
    }
}
