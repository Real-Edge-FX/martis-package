<?php

namespace Martis\Profile;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Martis\Auth\GuardCatalog;
use Martis\Contracts\ProfileResourceContract;
use Martis\Support\ModelUniqueRule;

/**
 * Default profile resource implementation.
 *
 * To customise it, extend it and name the subclass in `profile.resource`.
 */
class ProfileResource implements ProfileResourceContract
{
    /** {@inheritdoc} */
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
            $disk = (string) config('martis.profile.avatar.disk', 'public');
            $data['avatar_url'] = app(AvatarService::class)->resolveUrl((string) $user->{$avatarColumn}, $disk);
        }

        if ($twoFactorEnabled) {
            $data['two_factor_enabled'] = ! is_null($user->two_factor_confirmed_at ?? null);
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     *
     * The email is unique among the users of the signed-in user's own table
     * (the Martis guard's model), not the app's `users`: with a custom
     * MARTIS_GUARD, a site account's email is no conflict and another
     * admin's is.
     */
    public function updateRules(Authenticatable $user): array
    {
        $unique = $user instanceof Model
            ? ModelUniqueRule::make($user, 'email')->ignoreModel($user)
            : Rule::unique(GuardCatalog::martisUserModel(), 'email')->ignore($user->getAuthIdentifier());

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', $unique],
        ];
    }

    /** {@inheritdoc} */
    public function applyUpdate(Authenticatable $user, array $data): void
    {
        /** @var Model&Authenticatable $user */
        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
        ])->save();
    }
}
