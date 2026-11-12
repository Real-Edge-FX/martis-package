<?php

declare(strict_types=1);

namespace Martis\Auth;

use Illuminate\Support\Arr;
use Illuminate\Support\ViewErrorBag;

/**
 * What `window.MartisConfig.flash` carries (v2.21.0): the one flashed message
 * the SPA shell shows on arrival.
 *
 * The SSO callback refuses a sign-in with `redirect()->route('martis.login')
 * ->withErrors(['sso' => text])`, and the login page is the SPA shell, which a
 * server-side flash cannot reach on its own. The shell publishes the `sso`
 * message of the error bag here, as a string, and nothing else of the bag:
 * the page is public, and the bag also holds field errors and whatever the app
 * flashed.
 *
 * It reads the session's flash without consuming it. Laravel ages the flash at
 * the end of the request, so the text is on the page that follows the redirect
 * and a reload shows none.
 */
final class PublishedFlash
{
    /**
     * @return array{sso: string|null}
     */
    public static function toArray(): array
    {
        return ['sso' => self::sso()];
    }

    private static function sso(): ?string
    {
        $request = request();

        if (! $request->hasSession()) {
            return null;
        }

        $errors = $request->session()->get('errors');

        if (! $errors instanceof ViewErrorBag) {
            return null;
        }

        // Read as data, not through first(): a flashed value is whatever the
        // app put in the bag, and only a string is published.
        $message = Arr::get($errors->getBag('default')->getMessages(), 'sso.0');

        return is_string($message) && $message !== '' ? $message : null;
    }
}
