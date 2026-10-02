<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Martis\Auth\GuardCatalog;
use Martis\Profile\EmailChange;

/**
 * The link mailed to the new address of an email change
 * (`GET /{martis-path}/profile/email/confirm/{id}`, v2.4.0).
 *
 * Public, like the email verification link: the signed URL, issued to a
 * signed-in user who gave their current password and sent to the new address,
 * is the proof, so following it needs no session (the mailbox is often on
 * another device). It redirects, with the outcome in `?email_change=`:
 * to the profile page when this browser is signed in, to the login page
 * otherwise. See EmailChange for what it checks.
 */
class ProfileEmailChangeController extends MartisController
{
    public function confirm(Request $request, EmailChange $change, string $id): RedirectResponse
    {
        $outcome = EmailChange::INVALID;

        if ((bool) config('martis.profile.enabled', true) && $request->hasValidSignature()) {
            $from = $request->query('from');
            $to = $request->query('to');

            $outcome = is_string($from) && is_string($to)
                ? $change->confirm($id, $from, $to)
                : EmailChange::INVALID;
        }

        $base = '/'.ltrim((string) config('martis.path', 'martis'), '/');
        $signedIn = auth()->guard(GuardCatalog::martis())->check();

        return redirect(($signedIn ? $base.'/profile' : $base.'/login').'?email_change='.$outcome);
    }
}
