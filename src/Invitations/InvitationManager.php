<?php

namespace Martis\Invitations;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Martis\Contracts\RegistersUsers;
use Martis\Invitations\Events\InvitationAccepted;
use Martis\Invitations\Events\InvitationCreated;
use Martis\Invitations\Events\InvitationRevoked;

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

        // Not inside a transaction, so a plain dispatch is fine (nothing to roll back).
        event(new InvitationCreated($invitation));

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
     * The whole flow runs inside a single DB transaction. The pending ->
     * accepted flip is a compare-and-set UPDATE that only one caller can win
     * (`$claimed === 1`); the row lock it takes serializes concurrent
     * claimers, so single-use survives even under a race. Every unacceptable
     * state — unknown, expired, revoked, already-used token, or an email that
     * already belongs to a user — throws the neutral
     * {@see InvalidInvitationException}, and the transaction rollback returns
     * the invitation to `pending` so the accept endpoint cannot be used to
     * enumerate valid invitations.
     *
     * Because {@see createUser()} runs inside the transaction too, a
     * validation failure there (short/mismatched password, etc.) rolls the
     * claim back and the {@see ValidationException}
     * propagates OUT of accept() unchanged — the invitation stays `pending`
     * and remains retryable (the controller turns it into a 422).
     *
     * @param  array<string, mixed>  $signup
     *
     * @throws InvalidInvitationException
     * @throws ValidationException
     */
    public function accept(string $rawToken, array $signup): Authenticatable
    {
        $hash = $this->hashToken($rawToken);

        return DB::transaction(function () use ($hash, $signup): Authenticatable {
            // Atomic single-use claim: only one caller can flip pending -> accepted.
            // The row lock this UPDATE takes serializes concurrent claimers.
            $claimed = Invitation::query()
                ->where('token', $hash)
                ->where('status', Invitation::STATUS_PENDING)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->update(['status' => Invitation::STATUS_ACCEPTED, 'accepted_at' => now()]);

            if ($claimed !== 1) {
                throw new InvalidInvitationException; // nothing was ours; the transaction rolls back
            }

            $invitation = Invitation::query()->where('token', $hash)->firstOrFail();

            // Anti-takeover: never create/overwrite an already-registered email.
            // No manual rollback needed — throwing rolls the transaction back,
            // which undoes the claim and returns the row to pending.
            if ($this->emailExists($invitation->email)) {
                throw new InvalidInvitationException;
            }

            // Signup whitelist: only configured fields are read from the client; email is authoritative.
            /** @var list<string> $allowed */
            $allowed = (array) config('martis.invitations.signup_fields', ['name', 'password']);
            $safe = array_intersect_key($signup, array_flip([...$allowed, 'password_confirmation']));

            // createUser() runs the shared registration pipeline; a ValidationException
            // here propagates out unchanged and the rollback keeps the invitation pending.
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

            // Runs inside this DB::transaction(); fire only after it actually
            // commits so a claim that rolls back never emits the event.
            DB::afterCommit(fn () => event(new InvitationAccepted($invitation, $user)));

            return $user;
        });
    }

    /**
     * Re-issue a fresh raw token for a still-pending invitation, throttled
     * to prevent notification spam. Repeated calls inside
     * `resend_throttle_seconds` of the invitation's last `updated_at`
     * throw {@see InvalidInvitationException} instead of silently no-oping,
     * so a caller (e.g. a controller) can surface the throttle as an error.
     * On success only the hash is persisted; the new raw token is set as a
     * transient in-memory property for the caller to re-send.
     *
     * @throws InvalidInvitationException
     */
    public function resend(Invitation $invitation): void
    {
        if ($invitation->status !== Invitation::STATUS_PENDING) {
            throw new InvalidInvitationException;
        }

        $throttleSeconds = (int) config('martis.invitations.resend_throttle_seconds', 60);

        if ($invitation->updated_at !== null && $invitation->updated_at->diffInSeconds(now()) < $throttleSeconds) {
            throw new InvalidInvitationException;
        }

        $raw = $this->generateRawToken();

        $invitation->forceFill(['token' => $this->hashToken($raw)])->save();

        $invitation->rawToken = $raw; // transient, in-memory only, for the notification URL
    }

    /**
     * Revoke a still-pending invitation, permanently blocking accept().
     *
     * @throws InvalidInvitationException
     */
    public function revoke(Invitation $invitation): void
    {
        if ($invitation->status !== Invitation::STATUS_PENDING) {
            throw new InvalidInvitationException;
        }

        $invitation->forceFill(['status' => Invitation::STATUS_REVOKED])->save();

        // Not inside a transaction, so a plain dispatch is fine (nothing to roll back).
        event(new InvitationRevoked($invitation));
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
