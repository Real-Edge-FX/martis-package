<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Martis\Auth\GuardCatalog;
use Martis\Profile\EmailChange;

/**
 * The link mailed to the new address of an email change
 * (`/{martis-path}/profile/email/confirm/{id}`, v2.4.0).
 *
 * Opening the link changes nothing (`show()`): a mail scanner, a link preview
 * or a prefetch loads a mailed GET, and in the pre-hijack scenario an attacker
 * names the victim's address as the "new" one, so a bare GET must never apply
 * the change. It serves the confirmation page; the change is the POST that
 * page sends when the person clicks the button (`confirm()`, CSRF-protected),
 * to the same signed URL. The same pattern as the magic-link sign-in.
 *
 * Public, like the email verification link: the signed URL, issued to a
 * signed-in user who gave their current password and sent to the new address,
 * is the proof, so following it needs no session (the mailbox is often on
 * another device). The POST answers `{outcome, redirect}`: the page goes to
 * the profile when this browser is signed in, to the login page otherwise,
 * with the outcome in `?email_change=`. See EmailChange for what it checks.
 */
class ProfileEmailChangeController extends MartisController
{
    /**
     * The page the emailed link opens: the SPA shell, which asks to confirm.
     * Reads nothing and writes nothing; a link that is not validly signed, or
     * a disabled profile or e-mail change ({@see EmailChange::allowed()}),
     * goes to the login page as `invalid`.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        if (! EmailChange::allowed() || ! $request->hasValidSignature()) {
            return redirect($this->basePath().'/login?email_change='.EmailChange::INVALID);
        }

        // The URL carries the signed address: keep it out of caches and out
        // of the Referer of any request the page makes.
        return response(view('martis::app'))
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Apply the change, once the page confirmed it.
     *
     * @response array{outcome: string, redirect: string}
     */
    public function confirm(Request $request, EmailChange $change, string $id): JsonResponse
    {
        $outcome = EmailChange::INVALID;

        if (EmailChange::allowed() && $request->hasValidSignature()) {
            $from = $request->query('from');
            $to = $request->query('to');

            $outcome = is_string($from) && is_string($to)
                ? $change->confirm($id, $from, $to)
                : EmailChange::INVALID;
        }

        $base = $this->basePath();
        $signedIn = auth()->guard(GuardCatalog::martis())->check();

        return response()->json([
            'outcome' => $outcome,
            'redirect' => ($signedIn ? $base.'/profile' : $base.'/login').'?email_change='.$outcome,
        ]);
    }

    private function basePath(): string
    {
        return '/'.ltrim((string) config('martis.path', 'martis'), '/');
    }
}
