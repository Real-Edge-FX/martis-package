<?php

declare(strict_types=1);

namespace Martis\Sso\Providers;

use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use JsonException;
use Martis\Http\Middleware\SpendSignedToken;
use Martis\Sso\Contracts\SsoProviderContract;
use Martis\Sso\SignedToken\ConsumerSettings;
use Martis\Sso\SignedToken\IssuerSettings;
use Martis\Sso\SignedToken\ParsedToken;
use Martis\Sso\SignedToken\PasetoV4Public;
use Martis\Sso\SignedToken\Reason;
use Martis\Sso\SignedToken\Urls;
use Martis\Sso\SsoCache;
use Martis\Sso\SsoException;
use Martis\Sso\SsoIdentity;
use stdClass;
use Throwable;

/**
 * Signs a user in through another Martis panel with one redirect and a
 * short-lived, one-time, asymmetric token (v2.21.0): `driver => 'signed-token'`
 * in a provider block, whatever the block is named.
 *
 *   redirect()         a random `state` and its creation time go into the
 *                      browser's session, the browser goes to `authorize_url` with `audience` and `state`
 *   resolveIdentity()  the callback's `token` (PASETO v4.public, see
 *                      {@see PasetoV4Public}) is checked, in this order: its
 *                      shape, its signature against every configured key, its
 *                      claims (issuer, audience, expiry, age, environment: a token that carries the
 *                      environment claim needs a verifier with `environment`), the
 *                      one-time `jti`, and last the state (session = query =
 *                      claim, pulled when checked, at most ten minutes old)
 *
 * Every refusal is an {@see SsoException} with its own
 * {@see Reason}. The jti is recorded after the checks that say the token is
 * genuine, in time and meant for this verifier, and BEFORE the state (v2.22.0):
 * the first arrival of such a token uses it up whatever the state says, so a
 * callback that fails the state check in a victim's browser (an attacker made
 * the victim's signed-in browser fetch a token for the attacker's state) leaves
 * nothing to replay from a log. A token refused earlier (bad signature, other
 * audience, expired, other environment) is not recorded. Nothing about the
 * token or a key goes into a message.
 *
 * The callback route runs everything up to and including the `jti` record in a
 * middleware that spends the token and only then applies the throttle
 * ({@see spend()}), so a throttled (429)
 * callback has still used the token up; {@see resolveIdentity()} then only
 * checks the state. Used alone, it does every step itself.
 *
 * Not OIDC and not OAuth: a Martis panel signs for its own users, and one
 * other panel trusts that key. For a third-party IdP use the Socialite
 * providers.
 */
final class SignedTokenProvider implements SsoProviderContract
{
    /** String claims every token carries; `email_verified` (a boolean) is checked apart. */
    private const REQUIRED_CLAIMS = ['iss', 'aud', 'sub', 'iat', 'exp', 'jti', 'state', 'email'];

    /**
     * How long the state `redirect()` put in the session is good for (v2.22.0):
     * the person has this long to sign in at the issuing panel and come back.
     * Not a config key; a slow sign-in (an MFA prompt, a forgotten password)
     * that takes longer starts over.
     */
    public const STATE_LIFETIME_SECONDS = 600;

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
        $request->session()->put($this->stateKey(), ['value' => $state, 'at' => now()->getTimestamp()]);

        return redirect()->away(Urls::withQuery($settings->authorizeUrl, [
            'audience' => $settings->audience,
            'state' => $state,
        ]));
    }

    /**
     * Steps 1 to 3 of the sign-in (v2.22.0): the structure, the signature, the
     * claims and times, the environment and the ONE-TIME `jti`, with their
     * outcome kept on the request for {@see resolveIdentity()}. A refusal is
     * kept, not thrown, and so is a configuration error.
     *
     * The callback route runs it from a middleware that applies the throttle
     * only afterwards ({@see SpendSignedToken}), so that using the
     * token up does not depend on the request getting past a rate limit: a
     * victim's callback that is answered 429 would otherwise leave the token
     * good for its lifetime for whoever reads that URL. The work done before
     * the throttle is bounded: a token over {@see PasetoV4Public::MAX_TOKEN_LENGTH}
     * is refused before it is parsed, and nothing is written to the cache for a
     * token whose signature does not verify.
     */
    public function spend(Request $request): void
    {
        try {
            $outcome = $this->verifyAndSpend($request);
        } catch (Throwable $e) {
            $outcome = $e;
        }

        $request->attributes->set($this->outcomeKey(), $outcome);
    }

    public function resolveIdentity(Request $request): SsoIdentity
    {
        // What spend() decided, when the route's middleware ran it; else done here.
        $kept = $request->attributes->get($this->outcomeKey());
        if ($kept instanceof Throwable) {
            throw $kept;
        }
        $outcome = is_array($kept) ? $kept : $this->verifyAndSpend($request);
        $claims = $outcome['claims'];

        // 4. The browser session that started the flow.
        $this->checkState($request, (string) $claims['state']);

        $roles = $claims[$outcome['role_claim']] ?? [];
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

    /**
     * Steps 1 to 3: structure, signature, claims and times, environment, one
     * use. Nothing about the browser session.
     *
     * @return array{claims: array<string, mixed>, role_claim: string}
     */
    private function verifyAndSpend(Request $request): array
    {
        PasetoV4Public::ensureAvailable();
        $settings = ConsumerSettings::fromConfig($this->name);

        $token = $request->query('token');
        if (! is_string($token) || $token === '') {
            throw $this->refuse(Reason::MALFORMED, 'the callback carries no token');
        }

        // 1. Structure (the length first), then the signature.
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

        // 3. One use, recorded BEFORE the state is looked at: a token that is
        //    validly signed, in time and for this audience is used up by the
        //    first request that carries it, whatever that request's state is.
        //    A token whose callback fails the state check in a victim's
        //    browser (state fixation) must not stay good for its lifetime in
        //    the access log of whoever reads that URL.
        $this->useOnce((string) $claims['jti'], min($exp, $iat + $settings->maxAgeSeconds) + $skew - $now);

        return ['claims' => $claims, 'role_claim' => $settings->roleClaim];
    }

    private function outcomeKey(): string
    {
        return 'martis.sso.signed_token.'.$this->name;
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
     * that carry it: a staging token does not sign in to production. The lock
     * is a pair (v2.22.0): a token that carries the environment claim at a
     * verifier with no `environment` is refused too, because the issuer is
     * locked and this side would ignore it.
     *
     * @param  array<string, mixed>  $claims
     */
    private function checkEnvironment(array $claims, ConsumerSettings $settings): void
    {
        if ($settings->environment === null) {
            if (array_key_exists(IssuerSettings::ENVIRONMENT_CLAIM, $claims)) {
                throw $this->refuse(Reason::ENVIRONMENT, 'the issuer is locked to an environment and this verifier is not: set `environment` in the provider block');
            }

            return;
        }

        $this->same($claims[IssuerSettings::ENVIRONMENT_CLAIM] ?? null, $settings->environment, Reason::ENVIRONMENT, 'the token is from another environment');
    }

    /**
     * The state the flow put in the session (pulled when it is checked: it
     * is good for one check, whatever its outcome, and for {@see self::STATE_LIFETIME_SECONDS} from the redirect),
     * the callback's `state` query parameter and the token's `state` claim
     * must all be the same. A state kept without its creation time (a flow
     * started before v2.22.0) is refused: the person starts over.
     */
    private function checkState(Request $request, #[\SensitiveParameter] string $claimed): void
    {
        $kept = $request->hasSession() ? $request->session()->pull($this->stateKey()) : null;
        $query = $request->query('state');

        $expected = null;
        if (is_array($kept) && is_string($kept['value'] ?? null) && is_int($kept['at'] ?? null)) {
            if (now()->getTimestamp() - $kept['at'] > self::STATE_LIFETIME_SECONDS) {
                // Same reason as a mismatch (`state`), but the log says which.
                throw $this->refuse(Reason::STATE, 'the state is older than its '.(self::STATE_LIFETIME_SECONDS / 60).' minutes lifetime, so the sign-in has to start again');
            }

            $expected = $kept['value'];
        }

        $matches = is_string($expected) && $expected !== ''
            && is_string($query)
            && (hash_equals($expected, $query) & hash_equals($expected, $claimed));

        if (! $matches) {
            throw $this->refuse(Reason::STATE, 'the state does not match this browser session');
        }
    }

    /**
     * Record the `jti` for as long as the token could still be accepted, plus
     * one second: the time checks accept the very last second of that window,
     * while a cache entry is gone at its expiry second, so without the extra
     * second the record vanishes on the last instant the token is still good.
     * The cache store ({@see SsoCache}) must be shared by every server of the panel and must not evict
     * early; when it fails the token is refused, not let through.
     */
    private function useOnce(#[\SensitiveParameter] string $jti, int $seconds): void
    {
        $key = 'martis_sso_jti:'.$this->name.':'.hash('sha256', $jti);
        // Resolved outside the try: an unknown store is a config error that
        // names the key, not a store failure.
        $cache = SsoCache::store();

        try {
            $added = $cache->add($key, true, max(1, $seconds + 1));
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
