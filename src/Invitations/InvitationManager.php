<?php

namespace Martis\Invitations;

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

    protected function generateRawToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    protected function hashToken(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
