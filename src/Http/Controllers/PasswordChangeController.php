<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Martis\Auth\PasswordChanger;
use Martis\Auth\PasswordChangeRequirement;
use Martis\Auth\PasswordPolicy;

/**
 * The forced password change (v2.3.0): the page a held user lands on and
 * the endpoint that sets their new password. Both run
 * RouteMiddleware::passwordChange(), the protected stack without the gate.
 */
class PasswordChangeController extends MartisController
{
    /** The SPA page, for a user the gate holds; anyone else goes to the dashboard. */
    public function show(Request $request): Response|RedirectResponse
    {
        abort_unless(PasswordChangeRequirement::enabled(), 404);

        if (! PasswordChangeRequirement::requiredFor($request, $this->panelUser())) {
            return redirect()->route('martis.index');
        }

        return response(view('martis::app'));
    }

    /**
     * Set the new password of a user the gate holds.
     *
     * @body-param string current_password required
     * @body-param string password required The new password, validated with the app's Password::defaults(); it must differ from the current one.
     * @body-param string password_confirmation required
     *
     * @response array{message: string}
     */
    public function update(Request $request, PasswordChanger $changer): JsonResponse
    {
        if (! PasswordChangeRequirement::enabled()) {
            return response()->json(['message' => 'Not Found.'], 404);
        }

        $user = $this->panelUser();

        if (! $user instanceof Model || ! PasswordChangeRequirement::requiredFor($request, $user)) {
            return response()->json(['message' => 'A password change is not required.'], 403);
        }

        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', PasswordPolicy::rule(), 'confirmed', 'different:current_password'],
        ]);

        $changer->change($user, (string) $request->input('password'), forced: true);

        return response()->json(['message' => __('martis::auth.password_change_done')]);
    }

    private function panelUser(): ?Authenticatable
    {
        /** @var string|null $guard */
        $guard = config('martis.guard');

        return auth()->guard($guard)->user();
    }
}
