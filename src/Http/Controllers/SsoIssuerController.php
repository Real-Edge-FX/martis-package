<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Martis\Impersonation\ImpersonationManager;
use Martis\Sso\SignedToken\IssuerSettings;
use Martis\Sso\SignedToken\TokenIssuer;
use Martis\Sso\SignedToken\Urls;
use Martis\Support\TranslatedLine;

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
    public function issue(Request $request, string $audience): RedirectResponse
    {
        // 404 for everything the route should not reveal: the issuer is off,
        // or the audience is not one of ours.
        if (! IssuerSettings::enabled() || ! IssuerSettings::hasAudience($audience)) {
            abort(404);
        }

        $this->requireNavigation($request);

        $state = $request->query('state');
        if (! is_string($state) || preg_match(TokenIssuer::STATE_PATTERN, $state) !== 1) {
            abort(422, 'The state parameter is required: 16 to 128 characters of A-Z, a-z, 0-9, - and _.');
        }

        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        // The guard holds the impersonated user while an impersonation runs. A
        // token for that user would open a full session for them at the other
        // panel, with no impersonation marker and none of its limits, and
        // that panel's admins never granted the operator the impersonation.
        // Issuing for the operator instead would be a surprise: refuse.
        if (app(ImpersonationManager::class)->isActive()) {
            abort(403, TranslatedLine::get('martis::messages.sso_issue_impersonating'));
        }

        $issuer = TokenIssuer::fromConfig();

        // Who may sign in to this audience: any panel user, or only those the
        // gate lets through (an app gate or a permission package's check).
        $ability = $issuer->abilityFor($audience);
        if ($ability !== null && ! Gate::forUser($user)->allows($ability)) {
            abort(403, TranslatedLine::get('martis::messages.sso_issue_forbidden'));
        }

        $token = $issuer->issue($user, $audience, $state);

        $requestId = $request->headers->get('X-Request-Id');
        Log::channel(config('logging.default'))->info('SSO token issued', [
            'audience' => $audience,
            'user_id' => $user->getAuthIdentifier(),
            'request_id' => is_string($requestId) && $requestId !== '' ? mb_substr($requestId, 0, 128) : (string) Str::uuid(),
        ]);

        $response = redirect()->away(Urls::withQuery($issuer->callbackUrl($audience), ['token' => $token, 'state' => $state]));
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * When the browser sends Fetch Metadata, it has to say this is a
     * top-level navigation. A client that sends none (an old browser, curl)
     * is not refused: it still needs the signed-in session's cookie.
     */
    private function requireNavigation(Request $request): void
    {
        $mode = $request->headers->get('Sec-Fetch-Mode');
        $dest = $request->headers->get('Sec-Fetch-Dest');

        if (($mode !== null && strtolower($mode) !== 'navigate') || ($dest !== null && strtolower($dest) !== 'document')) {
            abort(403);
        }
    }
}
