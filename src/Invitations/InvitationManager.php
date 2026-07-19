<?php

namespace Martis\Invitations;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Contracts\RegistersUsers;

class InvitationManager
{
    public function invite(string $email, ?string $role = null, array $metadata = []): Invitation
    {
        $raw = $this->generateRawToken();

        $invitation = Invitation::create([
            'email' => mb_strtolower($email),
            'token' => $this->hashToken($raw),
            'status' => Invitation::STATUS_PENDING,
            'role' => $role,
            'invited_by' => auth()->guard(config('martis.guard'))->id(),
            'expires_at' => now()->addHours((int) config('martis.invitations.expires_after_hours', 72)),
            'metadata' => $metadata ?: null,
        ]);

        $invitation->rawToken = $raw; // transient, in-memory only, for the notification URL

        return $invitation;
    }

    public function findByRawToken(string $rawToken): ?Invitation
    {
        return Invitation::where('token', $this->hashToken($rawToken))->first();
    }

    /**
     * Atomically claim a pending, non-expired invitation, create the user
     * through the shared registration pipeline, assign the invitation role,
     * mark the invitation accepted, and return the new user.
     *
     * The pending -> accepted flip is a single compare-and-set UPDATE that
     * only one caller can win (`$claimed === 1`). Every unacceptable state
     * — unknown, expired, revoked, already-used token, or an email that
     * already belongs to a user — throws the neutral
     * {@see InvalidInvitationException} so the accept endpoint cannot be
     * used to enumerate valid invitations.
     *
     * @param  array<string, mixed>  $signup
     *
     * @throws InvalidInvitationException
     */
    public function accept(string $rawToken, array $signup): Authenticatable
    {
        $hash = $this->hashToken($rawToken);

        // Atomic single-use claim: only one caller can flip pending -> accepted.
        $claimed = Invitation::query()
            ->where('token', $hash)
            ->where('status', Invitation::STATUS_PENDING)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->update(['status' => Invitation::STATUS_ACCEPTED, 'accepted_at' => now()]);

        if ($claimed !== 1) {
            throw new InvalidInvitationException;
        }

        $invitation = Invitation::query()->where('token', $hash)->firstOrFail();

        // Anti-takeover: never create/overwrite an already-registered email.
        if ($this->emailExists($invitation->email)) {
            // roll the claim back so the row does not read as accepted-without-user
            $invitation->forceFill(['status' => Invitation::STATUS_PENDING, 'accepted_at' => null])->save();
            throw new InvalidInvitationException;
        }

        // Signup whitelist: only configured fields are read from the client; email is authoritative.
        /** @var list<string> $allowed */
        $allowed = (array) config('martis.invitations.signup_fields', ['name', 'password']);
        $safe = array_intersect_key($signup, array_flip([...$allowed, 'password_confirmation']));

        $user = $this->createUser($invitation, $safe);

        if ($invitation->role !== null && method_exists($user, 'assignRole')) {
            $user->assignRole($invitation->role); // Spatie soft-dep
        }

        if (
            config('martis.invitations.mark_email_verified_on_accept', true)
            && $user instanceof Model
            && Schema::hasColumn($user->getTable(), 'email_verified_at')
        ) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $userId = $user instanceof Model ? $user->getKey() : $user->getAuthIdentifier();
        $invitation->forceFill(['accepted_user_id' => $userId])->save();

        // TODO(Task 7): event(new \Martis\Invitations\Events\InvitationAccepted($invitation, $user));

        return $user;
    }

    /**
     * Create the user for an accepted invitation. The ONLY override seam.
     *
     * The default delegates to the shared {@see RegistersUsers} pipeline so
     * a single registration path governs validation, hashing, default-role
     * assignment, and the `Registered` event. Consumers rebind
     * `RegistersUsers` (not this method) for app-wide changes; overriding
     * this method is reserved for invitation-specific user shaping.
     *
     * @param  array<string, mixed>  $signup
     */
    protected function createUser(Invitation $invitation, array $signup): Authenticatable
    {
        // Build a minimal in-memory request: name/password from the client,
        // email authoritative from the invitation (client can never set it).
        $request = Request::create('', 'POST', [
            'name' => $signup['name'] ?? null,
            'email' => $invitation->email,
            'password' => $signup['password'] ?? null,
            'password_confirmation' => $signup['password_confirmation'] ?? ($signup['password'] ?? null),
        ]);

        return app(RegistersUsers::class)->register($request);
    }

    protected function emailExists(string $email): bool
    {
        /** @var class-string<Model>|null $model */
        $model = config('auth.providers.users.model');

        if ($model === null) {
            return false;
        }

        return $model::query()->where('email', mb_strtolower($email))->exists();
    }

    protected function generateRawToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    protected function hashToken(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
