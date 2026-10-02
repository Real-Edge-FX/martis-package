<?php

declare(strict_types=1);

namespace Martis\Profile;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Martis\Auth\GuardCatalog;
use Martis\Contracts\ProfileResourceContract;
use Martis\Contracts\SendsEmailVerification;
use Martis\Support\CanonicalUrl;

/**
 * Changing the email address of the signed-in user (v2.4.0).
 *
 * The address is the identity of the account: it receives the password
 * reset, the sign-in link and the verification, and an SSO provider that
 * matches by email adopts the local account that holds it. So the profile
 * does not write a new one on the spot. The request asks the current
 * password (ProfileController::update), mails a confirmation link to the NEW
 * address and a notice to the OLD one, and only the confirmation switches the
 * address (confirm()), after it checks again that the address is still free.
 *
 * Nothing is stored for it: the link is a temporary signed URL, on APP_URL,
 * that carries the user's id, the address to switch to (`to`) and a
 * fingerprint of the address it leaves (`from`). Once the address changes,
 * by this link or any other way, the fingerprint no longer matches and every
 * link issued before it is dead, so a link works once and the first one
 * followed wins.
 */
final class EmailChange
{
    /** The route of the confirmation link. */
    public const ROUTE = 'martis.profile.email.confirm';

    /** What confirm() answers. */
    public const CHANGED = 'changed';

    public const INVALID = 'invalid';

    public const REJECTED = 'rejected';

    /** Minutes the confirmation link stays valid. */
    public function ttlMinutes(): int
    {
        return max(1, (int) config('martis.profile.email_change.ttl_minutes', 60));
    }

    /** The address the user holds now, as stored. */
    public function currentEmail(Authenticatable $user): string
    {
        $email = data_get($user, 'email');

        return is_string($email) ? $email : '';
    }

    /**
     * Whether $submitted is another address than the user's: the same
     * address in other letter case is the same address.
     */
    public function isChange(Authenticatable $user, mixed $submitted): bool
    {
        return is_string($submitted)
            && trim($submitted) !== ''
            && strcasecmp(trim($submitted), $this->currentEmail($user)) !== 0;
    }

    /**
     * Ask for the change: the confirmation link goes to the new address, a
     * notice of the request to the old one. Nothing changes yet.
     */
    public function request(Authenticatable $user, string $newEmail): void
    {
        $newEmail = trim($newEmail);
        $current = $this->currentEmail($user);
        $ttl = $this->ttlMinutes();

        $url = CanonicalUrl::temporarySignedRoute(self::ROUTE, now()->addMinutes($ttl), [
            'id' => (string) $user->getAuthIdentifier(),
            'from' => $this->fingerprint($current),
            'to' => $newEmail,
        ]);

        Notification::route('mail', $newEmail)->notify(new EmailChangeConfirmationNotification($url, $ttl));

        if ($current !== '') {
            Notification::route('mail', $current)->notify(new EmailChangeRequestedNotification($newEmail, $ttl));
        }
    }

    /**
     * Follow a confirmation link: switch the address of user $id to $to,
     * when the link is still the one for the address the user holds and $to
     * still passes the rules of the profile (the address is free).
     *
     * @return self::CHANGED|self::INVALID|self::REJECTED
     */
    public function confirm(string $id, string $from, string $to): string
    {
        $to = trim($to);
        $user = GuardCatalog::martisUserModel()::query()->find($id);

        if (! $user instanceof Model || ! $user instanceof Authenticatable) {
            return self::INVALID;
        }

        $current = $this->currentEmail($user);

        // Used or superseded: the address is no longer the one the link was for.
        if ($to === '' || strcasecmp($to, $current) === 0 || ! hash_equals($this->fingerprint($current), $from)) {
            return self::INVALID;
        }

        // Free at the moment of the confirmation, not only when it was asked for.
        $rules = app(ProfileResourceContract::class)->updateRules($user)['email'] ?? ['required', 'email', 'max:255'];
        if (Validator::make(['email' => $to], ['email' => $rules])->fails()) {
            return self::REJECTED;
        }

        $user->forceFill(['email' => $to]);

        // The new address is not the one that was verified.
        if (($user instanceof MustVerifyEmail || config('martis.auth.email_verification.enabled', false))
            && array_key_exists('email_verified_at', $user->getAttributes())) {
            $user->forceFill(['email_verified_at' => null]);
        }

        $user->save();

        if ($current !== '') {
            Notification::route('mail', $current)->notify(new EmailChangedNotification($current, $to));
        }

        if (config('martis.auth.email_verification.enabled', false)) {
            app(SendsEmailVerification::class)->send($user);
        }

        return self::CHANGED;
    }

    private function fingerprint(string $email): string
    {
        return hash_hmac('sha256', strtolower($email), (string) config('app.key'));
    }
}
