<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Martis\Events\SsoIssueDenied;
use Martis\Events\SsoTokenIssued;
use Martis\Impersonation\ImpersonationManager;
use Martis\Sso\SignedToken\IssuerSettings;
use Martis\Sso\SignedToken\TokenIssuer;
use Martis\Sso\SignedToken\Urls;
use Martis\Sso\SsoRequestId;
use Martis\Support\TranslatedLine;
use Throwable;

/**
 * The issuing side of the signed-token SSO (v2.21.0), for the panel whose
 * users sign in to another Martis panel:
 *
 *   GET /{martis}/sso/issue/{audience}?state=...
 *
 * The route runs the full protected stack (signed in, 2FA passed, email
 * verified when required, the `viewMartis` gate); an anonymous visitor goes
 * through the login page and the URL is kept as the intended one. The
 * browser is redirected to the `callback_url` configured for that audience
 * and to nothing from the request, with the signed token and the `state`
 * appended. The token is in the query string of that URL: it lives
 * `ttl_seconds`, is used once, and the response says `no-store` and
 * `no-referrer`. Keep query strings of the callback path and of this issue
 * path out of access logs.
 *
 * A top-level navigation is what the legitimate flow is, so any page can send
 * a signed-in user here with a `state` the attacker chose (state fixation).
 * The package cannot refuse that here (`Sec-Fetch-Site` is `cross-site` in the
 * legitimate flow too); what protects the user is the verifier, which uses up
 * the token's one-time `jti` before it checks the state (v2.22.0), so the
 * victim's own failed callback burns the token a log could leak.
 *
 * No token is issued while an impersonation is active (403): the session
 * belongs to the operator, and the token would be the impersonated user's.
 *
 * Audit (v2.22.0): every token signed dispatches {@see SsoTokenIssued}, and
 * this controller's refusals dispatch {@see SsoIssueDenied} and log a warning
 * with the same `request_id`, for exactly six reasons: `unknown_audience`,
 * `not_navigation`, `invalid_state`, `impersonating`, `forbidden` and
 * `cannot_sign` (signing failed: the exception then goes on, a 500). The HTTP
 * answers are unchanged. A disabled issuer stays a silent 404. Refusals by the
 * route's middleware (not signed in, 2FA, email verification, the panel gate,
 * throttle) happen before this controller and raise no event. No token and no
 * `state` goes into an event or a log line. The events run inside the
 * request: a throwing listener of {@see SsoTokenIssued} stops the sign-in.
 *
 * An audience may set an `ability`: only users the gate allows for it get a
 * token (403 otherwise, nothing issued, no redirect).
 *
 * A request the browser marks as anything but a top-level navigation
 * (`Sec-Fetch-Mode` not `navigate`, `Sec-Fetch-Dest` not `document`: an
 * XHR, an iframe, an image) is refused, so another site cannot make the
 * signed-in browser fetch a token.
 */
class SsoIssuerController extends Controller
{
    /** Longest audience name (see {@see IssuerSettings::AUDIENCE_PATTERN}) that reaches a log line or an event. */
    private const MAX_AUDIENCE_LENGTH = 64;

    public function issue(Request $request, string $audience): RedirectResponse
    {
        // A disabled issuer is a silent 404: no log line, no event, so a
        // panel that does not use the issuer shows nothing for the route.
        if (! IssuerSettings::enabled()) {
            abort(404);
        }

        $requestId = SsoRequestId::from($request);
        $user = $request->user();

        // 404 for an audience that is not one of ours.
        if (! IssuerSettings::hasAudience($audience)) {
            $this->deny($user, $audience, SsoIssueDenied::UNKNOWN_AUDIENCE, $requestId, 404);
        }

        if (! $this->isNavigation($request)) {
            $this->deny($user, $audience, SsoIssueDenied::NOT_NAVIGATION, $requestId, 403);
        }

        $state = $request->query('state');
        if (! is_string($state) || preg_match(TokenIssuer::STATE_PATTERN, $state) !== 1) {
            $this->deny($user, $audience, SsoIssueDenied::INVALID_STATE, $requestId, 422, 'The state parameter is required: 16 to 128 characters of A-Z, a-z, 0-9, - and _.');
        }

        if ($user === null) {
            abort(401);
        }

        // The guard holds the impersonated user while an impersonation runs. A
        // token for that user would open a full session for them at the other
        // panel, with no impersonation marker and none of its limits, and
        // that panel's admins never granted the operator the impersonation.
        // Issuing for the operator instead would be a surprise: refuse.
        if (app(ImpersonationManager::class)->isActive()) {
            $this->deny($user, $audience, SsoIssueDenied::IMPERSONATING, $requestId, 403, TranslatedLine::get('martis::messages.sso_issue_impersonating'));
        }

        // An issuer block that is unusable throws, naming the key. The audit
        // trail records the denial first, then the exception goes on (500).
        try {
            $issuer = TokenIssuer::fromConfig();
        } catch (Throwable $e) {
            $this->record($user, $audience, SsoIssueDenied::CANNOT_SIGN, $requestId);

            throw $e;
        }

        // Who may sign in to this audience: any panel user, or only those the
        // gate lets through (an app gate or a permission package's check).
        $ability = $issuer->abilityFor($audience);
        if ($ability !== null && ! Gate::forUser($user)->allows($ability)) {
            $this->deny($user, $audience, SsoIssueDenied::FORBIDDEN, $requestId, 403, TranslatedLine::get('martis::messages.sso_issue_forbidden'));
        }

        try {
            $token = $issuer->issue($user, $audience, $state);
        } catch (Throwable $e) {
            // A user with no email, a `claims` class that throws or returns the
            // wrong shape: nothing was signed. The failure is not hidden (500).
            $this->record($user, $audience, SsoIssueDenied::CANNOT_SIGN, $requestId);

            throw $e;
        }

        Log::channel(config('logging.default'))->info('SSO token issued', [
            'audience' => $audience,
            'user_id' => $user->getAuthIdentifier(),
            'request_id' => $requestId,
        ]);
        SsoTokenIssued::dispatch($user, $audience, $requestId);

        $response = redirect()->away(Urls::withQuery($issuer->callbackUrl($audience), ['token' => $token, 'state' => $state]));
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * Refuse the request: one warning line and one {@see SsoIssueDenied}, then
     * the HTTP answer the route always gave. Neither the token (none was made)
     * nor the caller's `state` goes into either.
     */
    private function deny(?Authenticatable $user, string $audience, string $reason, string $requestId, int $status, ?string $message = null): never
    {
        $this->record($user, $audience, $reason, $requestId);

        abort($status, $message ?? '');
    }

    /** The warning line and the {@see SsoIssueDenied} of one refusal, with no answer of their own. */
    private function record(?Authenticatable $user, string $audience, string $reason, string $requestId): void
    {
        $audience = $this->boundedAudience($audience);

        Log::channel(config('logging.default'))->warning('SSO token issue denied', [
            'audience' => $audience,
            'user_id' => $user?->getAuthIdentifier(),
            'reason' => $reason,
            'request_id' => $requestId,
        ]);
        SsoIssueDenied::dispatch($user, $audience, $reason, $requestId);
    }

    /**
     * The audience of the URL as it may be logged: cut to 64 characters and
     * reduced to printable ASCII (anything else, a control character or a byte
     * of broken UTF-8 included, becomes `?`), because on the unknown-audience
     * path it is whatever the caller put in the URL.
     */
    private function boundedAudience(string $audience): string
    {
        return substr((string) preg_replace('/[^\x20-\x7E]/', '?', substr($audience, 0, self::MAX_AUDIENCE_LENGTH)), 0, self::MAX_AUDIENCE_LENGTH);
    }

    /**
     * When the browser sends Fetch Metadata, it has to say this is a
     * top-level navigation. A client that sends none (an old browser, curl)
     * is not refused: it still needs the signed-in session's cookie.
     */
    private function isNavigation(Request $request): bool
    {
        $mode = $request->headers->get('Sec-Fetch-Mode');
        $dest = $request->headers->get('Sec-Fetch-Dest');

        return ! (($mode !== null && strtolower($mode) !== 'navigate') || ($dest !== null && strtolower($dest) !== 'document'));
    }
}
