<?php

declare(strict_types=1);

namespace Martis\Sso\SignedToken;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Martis\Support\ConfigCallable;
use RuntimeException;

/**
 * Signs the token that lets a user of this panel sign in to another one:
 * PASETO v4.public over the claims below, footer `{"kid": ...}`.
 *
 *   iss    martis.auth.sso.issuer.issuer
 *   aud    the audience the user asked for
 *   sub    the user's id here, as a string
 *   iat    now (RFC 3339, UTC)
 *   exp    iat + ttl_seconds
 *   jti    128 random bits, hex: the verifier accepts each once
 *   state  the value the other panel kept in the browser's session
 *   email, name, roles (under `role_claim`), env (when `environment` is set)
 *
 * The role names are the user's Spatie roles when the model has
 * `getRoleNames()`, else none. A `claims` class
 * (`(Authenticatable $user, string $audience): array`) adds claims and
 * replaces email, name and roles; a reserved claim is refused.
 *
 * The secret key comes from `martis.auth.sso.issuer.private_key` only.
 */
final class TokenIssuer
{
    /** The state the other panel generates: URL-safe, 16 to 128 characters. */
    public const STATE_PATTERN = '/\A[A-Za-z0-9_-]{16,128}\z/';

    private function __construct(private readonly IssuerSettings $settings) {}

    /** @throws InvalidArgumentException when the issuer block is set but unusable */
    public static function fromConfig(): self
    {
        PasetoV4Public::ensureAvailable();

        return new self(IssuerSettings::fromConfig());
    }

    /**
     * The callback URL of an audience: the only place its token is sent to.
     *
     * @throws InvalidArgumentException when the audience is not configured
     */
    public function callbackUrl(string $audience): string
    {
        return $this->settings->audiences[$audience]
            ?? throw new InvalidArgumentException('The audience is not configured in ['.IssuerSettings::KEY.'.audiences].');
    }

    /**
     * The gate ability a user needs to obtain a token for the audience, or null
     * when any panel user may.
     */
    public function abilityFor(string $audience): ?string
    {
        $this->callbackUrl($audience);

        return $this->settings->abilities[$audience] ?? null;
    }

    /**
     * @throws InvalidArgumentException for an unknown audience, a state of the wrong shape or a bad claims class
     * @throws RuntimeException when the user has no email to sign
     */
    public function issue(Authenticatable $user, string $audience, string $state): string
    {
        $this->callbackUrl($audience);

        if (preg_match(self::STATE_PATTERN, $state) !== 1) {
            throw new InvalidArgumentException('The state must be 16 to 128 URL-safe characters (A-Z, a-z, 0-9, - and _).');
        }

        $now = now()->getTimestamp();
        $s = $this->settings;

        $claims = array_merge(
            $this->userClaims($user),
            $this->customClaims($user, $audience),
            [
                'iss' => $s->issuer,
                'aud' => $audience,
                'sub' => (string) $user->getAuthIdentifier(),
                'iat' => gmdate('Y-m-d\TH:i:sP', $now),
                'exp' => gmdate('Y-m-d\TH:i:sP', $now + $s->ttlSeconds),
                'jti' => bin2hex(random_bytes(16)),
                'state' => $state,
            ],
        );

        if ($s->environment !== null) {
            $claims[$s->environmentClaim] = $s->environment;
        }

        if (! is_string($claims['email'] ?? null) || $claims['email'] === '') {
            throw new RuntimeException('The user has no email to sign: the verifying panel requires one. Set it in the [martis.auth.sso.issuer.claims] class.');
        }

        return PasetoV4Public::sign(
            json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $s->secretKey,
            json_encode(['kid' => PasetoV4Public::keyId($s->publicKey)], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function userClaims(Authenticatable $user): array
    {
        $claims = [$this->settings->roleClaim => $this->roleNames($user)];

        foreach (['email', 'name'] as $attribute) {
            $value = data_get($user, $attribute);

            if (is_string($value) && $value !== '') {
                $claims[$attribute] = $value;
            }
        }

        return $claims;
    }

    /**
     * @return list<string>
     */
    private function roleNames(Authenticatable $user): array
    {
        if (! method_exists($user, 'getRoleNames')) {
            return [];
        }

        $names = $user->getRoleNames();
        $roles = [];

        foreach (is_iterable($names) ? $names : [] as $role) {
            $roles[] = (string) $role;
        }

        return $roles;
    }

    /**
     * What the `claims` class adds or replaces.
     *
     * @return array<string, mixed>
     */
    private function customClaims(Authenticatable $user, string $audience): array
    {
        $key = IssuerSettings::KEY.'.claims';
        $callable = ConfigCallable::resolve($this->settings->claims, $key);

        if ($callable === null) {
            return [];
        }

        $result = $callable($user, $audience);

        if (! is_array($result)) {
            throw new InvalidArgumentException("The [{$key}] class must return an array of claims, got ".get_debug_type($result).'.');
        }

        $reserved = [...IssuerSettings::RESERVED_CLAIMS, ...($this->settings->environment !== null ? [$this->settings->environmentClaim] : [])];

        foreach ($result as $claim => $value) {
            if (! is_string($claim) || $claim === '' || in_array($claim, $reserved, true)) {
                throw new InvalidArgumentException("The [{$key}] class may not set the claim [{$claim}]: ".implode(', ', $reserved).' are the issuer\'s own.');
            }

            if (in_array($claim, ['email', 'name'], true) && $value !== null && ! is_string($value)) {
                throw new InvalidArgumentException("The [{$key}] class must return [{$claim}] as a string, got ".get_debug_type($value).'.');
            }

            if ($claim === $this->settings->roleClaim && (! is_array($value) || ! array_is_list($value) || array_filter($value, static fn ($role) => ! is_string($role)) !== [])) {
                throw new InvalidArgumentException("The [{$key}] class must return [{$claim}] as a list of strings.");
            }
        }

        return $result;
    }
}
