<?php

declare(strict_types=1);

namespace Martis\Sso\SignedToken;

use InvalidArgumentException;

/**
 * The block of a `driver => 'signed-token'` provider
 * (`martis.auth.sso.providers.{name}`), validated: a value that is set but
 * unusable throws, naming its key. `environment_claim` without `environment`
 * is half a lock and throws, naming both.
 */
final class ConsumerSettings
{
    /**
     * @param  list<string>  $publicKeys  raw Ed25519 public keys, one per accepted signer
     */
    private function __construct(
        public readonly string $name,
        public readonly string $authorizeUrl,
        public readonly array $publicKeys,
        public readonly string $issuer,
        public readonly string $audience,
        public readonly int $maxAgeSeconds,
        public readonly int $clockSkewSeconds,
        public readonly string $roleClaim,
        public readonly ?string $environment,
        public readonly string $environmentClaim,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function fromConfig(string $name): self
    {
        $prefix = "martis.auth.sso.providers.{$name}.";
        $get = static fn (string $key): mixed => config($prefix.$key);

        $keys = $get('public_keys');
        if (! is_array($keys) || ! array_is_list($keys) || $keys === []) {
            throw new InvalidArgumentException("The [{$prefix}public_keys] config value must be a non-empty list of base64 Ed25519 public keys (see martis:sso:keys), got ".get_debug_type($keys).'.');
        }

        $publicKeys = [];
        foreach ($keys as $i => $key) {
            $publicKeys[] = ConfigReader::base64Key($key, "{$prefix}public_keys.{$i}", SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'an Ed25519 public key');
        }

        $environment = ConfigReader::optionalString($get('environment'), $prefix.'environment');
        $environmentClaim = ConfigReader::stringOr($get('environment_claim'), $prefix.'environment_claim', 'env');
        ConfigReader::assertEnvironmentPair($environment, $get('environment_claim'), $prefix.'environment', $prefix.'environment_claim');

        return new self(
            name: $name,
            authorizeUrl: Urls::assertUsable($get('authorize_url'), $prefix.'authorize_url'),
            publicKeys: $publicKeys,
            issuer: ConfigReader::string($get('issuer'), $prefix.'issuer'),
            audience: ConfigReader::string($get('audience'), $prefix.'audience'),
            maxAgeSeconds: ConfigReader::int($get('max_age_seconds'), $prefix.'max_age_seconds', 60, 1),
            clockSkewSeconds: ConfigReader::int($get('clock_skew_seconds'), $prefix.'clock_skew_seconds', 30, 0),
            roleClaim: ConfigReader::stringOr($get('role_claim'), $prefix.'role_claim', 'roles'),
            environment: $environment,
            environmentClaim: $environmentClaim,
        );
    }
}
