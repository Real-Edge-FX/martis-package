<?php

declare(strict_types=1);

namespace Martis\Auth;

use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Issues + consumes magic-link tokens for passwordless sign-in.
 *
 * Tokens are stored in Laravel's `password_reset_tokens` table (the
 * same one the password broker uses) under an `email` column prefixed
 * with `martis-magic:` so they never collide with real reset rows. The
 * shipped Martis migrations create that table; consumers using a
 * different schema can override the table name via
 * `auth.magic_link.table` config.
 *
 * One-shot: consuming a token deletes the row. Expired tokens are
 * GC'd whenever a new one is issued for the same email.
 */
class MagicLinkService
{
    public function __construct() {}

    /**
     * Issue a fresh token for the given email. Returns the plaintext
     * token (caller emails it) or `null` when the underlying token
     * table is missing — the controller surfaces a 503 in that case
     * so the consumer notices the broken install instead of seeing
     * silent failure on every request.
     */
    public function issue(string $email): ?string
    {
        if (! Schema::hasTable($this->table())) {
            return null;
        }

        $email = strtolower(trim($email));
        $key = $this->key($email);

        // GC: drop any prior token for this email so a leaked link
        // becomes inert as soon as a fresh one is issued.
        DB::table($this->table())->where('email', $key)->delete();

        $plain = Str::random(60);

        DB::table($this->table())->insert([
            'email' => $key,
            'token' => Hash::make($plain),
            'created_at' => Carbon::now(),
        ]);

        return $plain;
    }

    /**
     * Consume a token. Returns the email it was issued for on success,
     * or `null` when the token is missing, expired, or invalid. The
     * row is deleted on success, regardless of whether the caller
     * proceeds to authenticate the user.
     */
    public function consume(string $email, string $token): ?string
    {
        if (! Schema::hasTable($this->table())) {
            return null;
        }

        $email = strtolower(trim($email));
        $key = $this->key($email);

        $row = DB::table($this->table())->where('email', $key)->first();
        if ($row === null) {
            return null;
        }

        $createdAt = Carbon::parse((string) $row->created_at);
        if ($createdAt->lt(Carbon::now()->subMinutes($this->ttlMinutes()))) {
            DB::table($this->table())->where('email', $key)->delete();

            return null;
        }

        if (! Hash::check($token, (string) $row->token)) {
            return null;
        }

        DB::table($this->table())->where('email', $key)->delete();

        return $email;
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) config('martis.auth.magic_link.ttl_minutes', 15));
    }

    private function table(): string
    {
        return (string) config('martis.auth.magic_link.table', 'password_reset_tokens');
    }

    private function key(string $email): string
    {
        return 'martis-magic:'.$email;
    }
}
