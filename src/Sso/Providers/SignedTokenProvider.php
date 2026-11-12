<?php

declare(strict_types=1);

namespace Martis\Sso\Providers;

use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use JsonException;
use Martis\Sso\Contracts\SsoProviderContract;
use Martis\Sso\SignedToken\ConsumerSettings;
use Martis\Sso\SignedToken\ParsedToken;
use Martis\Sso\SignedToken\PasetoV4Public;
use Martis\Sso\SignedToken\Reason;
use Martis\Sso\SignedToken\Urls;
use Martis\Sso\SsoException;
use Martis\Sso\SsoIdentity;
use stdClass;
use Throwable;

/**
 * Signs a user in through another Martis panel with one redirect and a
 * short-lived, one-time, asymmetric token (v2.21.0): `driver => 'signed-token'`
 * in a provider block, whatever the block is named.
 *
 *   redirect()         a random `state` goes into the browser's session, the
 *                      browser goes to `authorize_url` with `audience` and `state`
 *   resolveIdentity()  the callback's `token` (PASETO v4.public, see
 *                      {@see PasetoV4Public}) is checked, in this order: its
 *                      shape, its signature against every configured key, its
 *                      claims (issuer, audience, expiry, age, environment),
 *                      the state (session = query = claim, pulled once), and
 *                      last the one-time `jti`
 *
 * Every refusal is an {@see SsoException} with its own
 * {@see Reason}. The jti is recorded last so a token this verifier rejected
 * for any other reason is not used up. Nothing about the token or a key goes
 * into a message.
 *
 * Not OIDC and not OAuth: a Martis panel signs for its own users, and one
 * other panel trusts that key. For a third-party IdP use the Socialite
 * providers.
 */
final class SignedTokenProvider implements SsoProviderContract
{
    /** String claims every token carries; `email_verified` (a boolean) is checked apart. */
    private const REQUIRED_CLAIMS = ['iss', 'aud', 'sub', 'iat', 'exp', 'jti', 'state', 'email'];

    /** Bounds the cache key and the memory a hostile jti can take. */
    private const MAX_JTI_LENGTH = 255;

    public function __construct(private readonly string $name = 'signed-token') {}

    public function name(): string
    {
        return $this->name;
    }

    public function redirect(Request $request): RedirectResponse
    {
        PasetoV4Public::ensureAvailable();
        $settings = ConsumerSettings::fromConfig($this->name);

        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $request->session()->put($this->stateKey(), $state);

        return redirect()->away(Urls::withQuery($settings->authorizeUrl, [
            'audience' => $settings->audience,
            'state' => $state,
        ]));
    }

    public function resolveIdentity(Request $request): SsoIdentity
    {
        PasetoV4Public::ensureAvailable();
        $settings = ConsumerSettings::fromConfig($this->name);

        $token = $request->query('token');
        if (! is_string($token) || $token === '') {
            throw $this->refuse(Reason::MALFORMED, 'the callback carries no token');
        }

        // 1. Structure, then the signature.
        $parsed = PasetoV4Public::parse($token);
        $this->verifySignature($parsed, $settings);

        // 2. Claims: presence and type, then the values.
        $claims = $this->claims($parsed, $settings);
        $iat = $this->time($claims['iat']);
        $exp = $this->time($claims['exp']);

        $this->same($claims['iss'], $settings->issuer, Reason::ISSUER, 'the issuer is not the configured one');
        $this->same($claims['aud'], $settings->audience, Reason::AUDIENCE, 'the audience is not the configured one');

        $now = now()->getTimestamp();
        $skew = $settings->clockSkewSeconds;

        if ($now > $exp + $skew) {
            throw $this->refuse(Reason::EXPIRED, 'the token has expired');
        }
        if ($iat > $now + $skew) {
            throw $this->refuse(Reason::NOT_YET_VALID, 'the token was issued in the future');
        }
        if ($now > $iat + $settings->maxAgeSeconds + $skew) {
            throw $this->refuse(Reason::EXPIRED, 'the token is older than max_age_seconds');
        }

        $this->checkEnvironment($claims, $settings);

        // 3. The browser session that started the flow.
        $this->checkState($request, (string) $claims['state']);

        // 4. One use, recorded last.
        $this->useOnce((string) $claims['jti'], min($exp, $iat + $settings->maxAgeSeconds) + $skew - $now);

        $roles = $claims[$settings->roleClaim] ?? [];
        $name = $claims['name'] ?? null;

        return new SsoIdentity(
            provider: $this->name,
            externalId: (string) $claims['sub'],
            email: (string) $claims['email'],
            name: is_string($name) && $name !== '' ? $name : null,
            externalRoles: is_array($roles) ? array_values(array_map('strval', $roles)) : [],
            raw: $claims,
            emailVerified: $claims['email_verified'],
        );
    }

    private function stateKey(): string
    {
        return 'martis_sso_state.'.$this->name;
    }

    /**
     * The signature must verify against one of the configured keys. The footer's
     * `kid` only picks which one; a `kid` that names no configured key is a bad
     * signature, and a token without one is tried against every key.
     */
    private function verifySignature(ParsedToken $parsed, ConsumerSettings $settings): void
    {
        $kid = null;

        if ($parsed->footer !== '') {
            try {
                $footer = json_decode($parsed->footer, false, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw $this->refuse(Reason::MALFORMED, 'the footer is not JSON');
            }

            if (! $footer instanceof stdClass) {
                throw $this->refuse(Reason::MALFORMED, 'the footer is not a JSON object');
            }

            $kid = is_string($footer->kid ?? null) ? $footer->kid : null;
        }

        foreach ($settings->publicKeys as $publicKey) {
            if ($kid !== null && ! hash_equals(PasetoV4Public::keyId($publicKey), $kid)) {
                continue;
            }

            if (PasetoV4Public::verify($parsed, $publicKey)) {
                return;
            }
        }

        throw $this->refuse(Reason::BAD_SIGNATURE, 'no configured key verifies the signature');
    }

    /**
     * The claims of a verified token, checked for presence and type.
     *
     * @return array<string, mixed>
     */
    private function claims(ParsedToken $parsed, ConsumerSettings $settings): array
    {
        try {
            $object = json_decode($parsed->message, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->refuse(Reason::MALFORMED, 'the payload is not JSON');
        }

        if (! $object instanceof stdClass) {
            throw $this->refuse(Reason::MALFORMED, 'the payload is not a JSON object');
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode($parsed->message, true, 16, JSON_THROW_ON_ERROR);

        foreach (self::REQUIRED_CLAIMS as $claim) {
            if (! isset($claims[$claim]) || $claims[$claim] === '') {
                throw $this->refuse(Reason::MISSING_CLAIM, "the claim [{$claim}] is missing");
            }

            if (! is_string($claims[$claim])) {
                throw $this->refuse(Reason::MALFORMED, "the claim [{$claim}] is not a string");
            }
        }

        // The issuer states whether the address is verified (a boolean, so it
        // is checked apart from the string claims above).
        if (! array_key_exists('email_verified', $claims) || $claims['email_verified'] === null) {
            throw $this->refuse(Reason::MISSING_CLAIM, 'the claim [email_verified] is missing');
        }

        if (! is_bool($claims['email_verified'])) {
            throw $this->refuse(Reason::MALFORMED, 'the claim [email_verified] is not a boolean');
        }

        if (strlen($claims['jti']) > self::MAX_JTI_LENGTH) {
            throw $this->refuse(Reason::MALFORMED, 'the claim [jti] is too long');
        }

        if (isset($claims['name']) && ! is_string($claims['name'])) {
            throw $this->refuse(Reason::MALFORMED, 'the claim [name] is not a string');
        }

        $roleClaim = $settings->roleClaim;
        if (array_key_exists($roleClaim, $claims)) {
            $roles = $claims[$roleClaim];

            if (! is_array($roles) || ! array_is_list($roles) || array_filter($roles, static fn (mixed $role): bool => ! is_string($role)) !== []) {
                throw $this->refuse(Reason::MALFORMED, 'the roles claim is not a list of strings');
            }
        }

        return $claims;
    }

    /** An RFC 3339 timestamp as Unix time. */
    private function time(mixed $value): int
    {
        if (is_string($value)) {
            foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'] as $format) {
                $date = DateTimeImmutable::createFromFormat($format, $value);
                $errors = DateTimeImmutable::getLastErrors();

                if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    return $date->getTimestamp();
                }
            }
        }

        throw $this->refuse(Reason::MALFORMED, 'a time claim is not an RFC 3339 timestamp');
    }

    private function same(mixed $claim, string $expected, string $reason, string $why): void
    {
        if (! is_string($claim) || ! hash_equals($expected, $claim)) {
            throw $this->refuse($reason, $why);
        }
    }

    /**
     * A verifier locked to an environment (`environment`) takes only tokens
     * that carry it: a staging token does not sign in to production.
     *
     * @param  array<string, mixed>  $claims
     */
    private function checkEnvironment(array $claims, ConsumerSettings $settings): void
    {
        if ($settings->environment === null) {
            return;
        }

        $this->same($claims[$settings->environmentClaim] ?? null, $settings->environment, Reason::ENVIRONMENT, 'the token is from another environment');
    }

    /**
     * The state the flow put in the session (pulled: it is good for one
     * attempt), the callback's `state` query parameter and the token's `state`
     * claim must all be the same.
     */
    private function checkState(Request $request, string $claimed): void
    {
        $expected = $request->hasSession() ? $request->session()->pull($this->stateKey()) : null;
        $query = $request->query('state');

        $matches = is_string($expected) && $expected !== ''
            && is_string($query)
            && (hash_equals($expected, $query) & hash_equals($expected, $claimed));

        if (! $matches) {
            throw $this->refuse(Reason::STATE, 'the state does not match this browser session');
        }
    }

    /**
     * Record the `jti` for as long as the token could still be accepted. The
     * cache must be shared by every server of the panel and must not evict
     * early; when it fails the token is refused, not let through.
     */
    private function useOnce(string $jti, int $seconds): void
    {
        $key = 'martis_sso_jti:'.$this->name.':'.hash('sha256', $jti);

        try {
            $added = Cache::add($key, true, max(1, $seconds));
        } catch (Throwable $e) {
            throw new SsoException('Signed token refused: the one-time store failed.', Reason::REPLAY_STORE, null, $e);
        }

        if (! $added) {
            throw $this->refuse(Reason::REPLAYED, 'the token was already used');
        }
    }

    private function refuse(string $reason, string $why): SsoException
    {
        return new SsoException('Signed token refused: '.$why.'.', $reason);
    }
}
