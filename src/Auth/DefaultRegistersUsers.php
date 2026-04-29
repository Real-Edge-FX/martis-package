<?php

namespace Martis\Auth;

use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Martis\Contracts\RegistersUsers;

/**
 * Default registration pipeline.
 *
 * Validates the incoming payload, creates a user via the model bound to
 * the auth provider configured under `auth.providers.users.model`,
 * optionally assigns a role (when `martis.auth.registration.default_role`
 * is set and the user model uses `Spatie\Permission\Traits\HasRoles`),
 * fires `Registered`, and returns the user.
 *
 * The behaviour is deliberately conservative — every consumer's needs
 * around plan assignment, audit logging, locale defaults, and the like
 * differ enough that reaching for the override hook
 * (`Martis\Contracts\RegistersUsers`) is the supported customisation
 * path. See `docs/authentication.md` → "Customising auth surfaces".
 */
class DefaultRegistersUsers implements RegistersUsers
{
    public function register(Request $request): Authenticatable
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        $modelClass = $this->userModel();

        /** @var Authenticatable&Model $user */
        $user = new $modelClass;
        $user->forceFill([
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'password' => Hash::make($data['password']),
        ])->save();

        $defaultRole = config('martis.auth.registration.default_role');
        if ($defaultRole !== null && method_exists($user, 'assignRole')) {
            $user->assignRole($defaultRole);
        }

        event(new Registered($user));

        return $user;
    }

    /**
     * @return class-string<Model&Authenticatable>
     */
    private function userModel(): string
    {
        $provider = config('auth.guards.'.config('martis.guard', 'web').'.provider', 'users');

        /** @var class-string<Model&Authenticatable>|null $modelClass */
        $modelClass = config("auth.providers.{$provider}.model");

        if ($modelClass === null) {
            throw new \RuntimeException(
                "No Eloquent model configured for auth provider '{$provider}'. ".
                'Set `auth.providers.'.$provider.'.model` in config/auth.php.'
            );
        }

        return $modelClass;
    }
}
