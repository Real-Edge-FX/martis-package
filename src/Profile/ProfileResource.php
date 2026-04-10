<?php

namespace Martis\Profile;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Martis\Contracts\ProfileResourceContract;

/**
 * Default profile resource implementation.
 *
 * Publish and extend via `config(martis.profile.resource)` to customise.
 */
class ProfileResource implements ProfileResourceContract
{
    /** {@inheritDoc} */
    public function toArray(Authenticatable $user): array
    {
        /** @var Model&Authenticatable $user */
        $avatarColumn = (string) config('martis.profile.avatar.column', 'profile_picture');
        $avatarEnabled = (bool) config('martis.profile.avatar.enabled', true);
        $twoFactorEnabled = (bool) config('martis.profile.two_factor.enabled', true);

        $data = [
            'name' => (string) ($user->name ?? ''),
            'email' => (string) ($user->email ?? ''),
            'avatar_url' => null,
            'two_factor_enabled' => false,
        ];

        if ($avatarEnabled && isset($user->{$avatarColumn}) && $user->{$avatarColumn}) {
            $resolver = config('martis.profile.avatar.url_resolver');
            if (is_callable($resolver)) {
                $data['avatar_url'] = (string) $resolver($user->{$avatarColumn});
            } else {
                $disk = (string) config('martis.profile.avatar.disk', 'public');
                $data['avatar_url'] = Storage::disk($disk)->url((string) $user->{$avatarColumn}); // @phpstan-ignore-line method.notFound
            }
        }

        if ($twoFactorEnabled) {
            $data['two_factor_enabled'] = ! is_null($user->two_factor_confirmed_at ?? null);
        }

        return $data;
    }

    /** {@inheritDoc} */
    public function updateRules(Authenticatable $user): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->getAuthIdentifier())],
        ];
    }

    /** {@inheritDoc} */
    public function applyUpdate(Authenticatable $user, array $data): void
    {
        /** @var Model&Authenticatable $user */
        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
        ])->save();
    }
}
