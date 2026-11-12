<?php

declare(strict_types=1);

namespace Martis\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * The page a person asked for before they were sent to the login page, handed
 * to the SPA when a JSON sign-in completes (v2.21.0).
 *
 * Laravel keeps it in the session as `url.intended` (`redirect()->guest()`,
 * the `RememberIntendedUrl` middleware). The form sign-in redirects to it; the
 * SPA signs in with a JSON request and used to open the dashboard instead, so
 * the response that completes a sign-in carries it as `intended_url`.
 *
 * It is pulled from the session once, and only returned when it is a same-origin
 * URL under the Martis base path (`martis.path`), as a path and query: any
 * other value is dropped and forgotten. A sign-in that a 2FA challenge, the
 * email verification or the forced password change still holds does not pull
 * it: it stays in the session for the step that completes the sign-in.
 */
final class IntendedUrl
{
    public const SESSION_KEY = 'url.intended';

    /** Longer than any page of the panel has a reason to be. */
    private const MAX_LENGTH = 2048;

    /**
     * The intended URL of a sign-in this request completed, or null: nothing
     * remembered, a value that is not allowed, or a gate that still holds the
     * user (then it is left in the session for the step that completes).
     */
    public static function pullWhenSignedIn(Request $request, Authenticatable $user): ?string
    {
        if (self::heldByAGate($request, $user)) {
            return null;
        }

        return self::pull($request);
    }

    /**
     * Take the value out of the session, always, and return it when allowed.
     */
    public static function pull(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        return self::allowed($request, $request->session()->pull(self::SESSION_KEY));
    }

    /**
     * The path and query of `$url` when it is a same-origin URL under the
     * base path of the panel; null otherwise. Never a protocol-relative or
     * foreign URL, a userinfo trick, a backslash or control character, or a
     * path the browser would rewrite out of the base path (a dot segment, an
     * encoded one, an encoded slash).
     */
    public static function allowed(Request $request, mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || strlen($url) > self::MAX_LENGTH) {
            return null;
        }

        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            if (! isset($parts['scheme'], $parts['host']) || ! self::sameOrigin($request, $parts)) {
                return null;
            }
        }

        $path = $parts['path'] ?? '';
        $base = '/'.trim((string) config('martis.path', 'admin'), '/');

        if (! str_starts_with($path, $base.'/')) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            $decoded = rawurldecode($segment);

            if (in_array($decoded, ['.', '..'], true) || str_contains($decoded, '/') || str_contains($decoded, '\\')) {
                return null;
            }
        }

        return isset($parts['query']) ? $path.'?'.$parts['query'] : $path;
    }

    /**
     * @param  array{scheme?: string, host?: string, port?: int}  $parts
     */
    private static function sameOrigin(Request $request, array $parts): bool
    {
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $default = $scheme === 'https' ? 443 : 80;
        $port = $parts['port'] ?? $default;

        $origin = $scheme.'://'.$host.($port === $default ? '' : ':'.$port);

        return $origin === strtolower($request->getSchemeAndHttpHost());
    }

    /** Whether a step before the dashboard still holds the user. */
    private static function heldByAGate(Request $request, Authenticatable $user): bool
    {
        if (app(\Martis\Profile\TwoFactorService::class)->isActive($user)
            && ! TwoFactorPass::holds($request->session(), $user)) {
            return true;
        }

        return EmailVerificationRequirement::requiredFor($user)
            || PasswordChangeRequirement::requiredFor($request, $user);
    }
}
