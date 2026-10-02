<?php

namespace Martis\Profile;

use Carbon\Carbon;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * TOTP-based Two-Factor Authentication service.
 *
 * Implements RFC 6238 (TOTP) using HMAC-SHA1 without external dependencies.
 * Generates OTP secrets, QR code SVGs, validates codes, and manages
 * recovery codes.
 */
class TwoFactorService
{
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const WINDOW = 1; // Time steps to check around current time

    /**
     * Per-instance cache for {@see hasLastUsedColumn()}. Keyed by "connection.table".
     *
     * @var array<string, bool>
     */
    private array $lastUsedColumnCache = [];

    /**
     * Tables a missing replay column was already reported for.
     *
     * @var array<string, true>
     */
    private array $warnedReplayGuardIsOff = [];

    /**
     * Generate a new TOTP secret and return setup data.
     *
     * @return array{secret: string, qr_code_svg: string, otpauth_uri: string}
     */
    public function generateSetup(Authenticatable $user): array
    {
        $this->requireModel($user);

        // Refuse to overwrite an already-confirmed secret. Without this, a
        // fresh setup call would silently replace the live 2FA secret (and
        // invalidate the user's recovery codes) with no re-authentication —
        // the caller must disable 2FA first (which is itself gated).
        if ($this->isEnabled($user)) {
            throw new \InvalidArgumentException('Two-factor authentication is already enabled. Disable it before generating a new secret.');
        }

        $secret = $this->generateSecret();
        $issuer = (string) config('martis.brand.name', 'Martis');
        $email = (string) ($user->email ?? 'user');

        $uri = sprintf(
            'otpauth://totp/%s%%3A%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($email),
            $secret,
            rawurlencode($issuer)
        );

        // Store pending secret on user (not confirmed yet)
        $user->two_factor_secret = encrypt($secret);
        $user->save();

        return [
            'secret' => $secret,
            'qr_code_svg' => $this->generateQrSvg($uri),
            'otpauth_uri' => $uri,
        ];
    }

    /**
     * Confirm 2FA by validating the provided OTP code.
     *
     * @return array{recovery_codes: list<string>}
     *
     * @throws \InvalidArgumentException if code is invalid
     */
    public function confirm(Authenticatable $user, string $code): array
    {
        $this->requireModel($user);
        if (! $user->two_factor_secret) {
            throw new \InvalidArgumentException('No pending 2FA setup found.');
        }

        $secret = (string) decrypt($user->two_factor_secret);

        if (! $this->verify($secret, $code)) {
            throw new \InvalidArgumentException('Invalid OTP code.');
        }

        $recoveryCodes = $this->generateRecoveryCodes();
        $hashed = array_map(fn (string $c) => bcrypt($c), $recoveryCodes);

        $user->two_factor_confirmed_at = now();
        $user->two_factor_recovery_codes = encrypt(json_encode($hashed));
        $user->save();

        return ['recovery_codes' => $recoveryCodes];
    }

    /**
     * Disable 2FA for the given user.
     */
    public function disable(Authenticatable $user): void
    {
        $this->requireModel($user);
        $user->two_factor_secret = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_recovery_codes = null;
        $user->save();
    }

    /**
     * Verify an OTP code against a user's 2FA secret.
     *
     * Implements TOTP replay protection: once a time step has been used
     * successfully, it cannot be reused within the same window.
     */
    public function verifyForUser(Authenticatable $user, string $code): bool
    {
        $this->requireModel($user);
        if (! $user->two_factor_secret || ! $user->two_factor_confirmed_at) {
            return false;
        }

        $secret = (string) decrypt($user->two_factor_secret);

        return $this->verifyAndTrack($user, $secret, $code);
    }

    /**
     * Verify a recovery code against the user's stored hashed codes.
     *
     * If valid, the code is consumed (removed from the list). A code is
     * single-use even when two requests carry it at once: the matching hash
     * is found first (bcrypt is slow, so no lock is held for it), then the
     * list is read again under a row lock and the hash is removed only if
     * it is still there. The request that finds it gone fails.
     */
    public function verifyRecoveryCode(Authenticatable $user, string $code): bool
    {
        $this->requireModel($user);
        if (! $user->two_factor_recovery_codes) {
            return false;
        }

        $matched = null;
        foreach ($this->storedRecoveryHashes($user->two_factor_recovery_codes) as $hash) {
            if (password_verify($code, $hash)) {
                $matched = $hash;

                break;
            }
        }

        if ($matched === null) {
            return false;
        }

        return $user->getConnection()->transaction(function () use ($user, $matched): bool {
            /** @var (Model&Authenticatable)|null $fresh */
            $fresh = $user->newModelQuery()->whereKey($user->getKey())->lockForUpdate()->first();
            if ($fresh === null || ! $fresh->two_factor_recovery_codes) {
                return false;
            }

            $hashed = $this->storedRecoveryHashes($fresh->two_factor_recovery_codes);
            $index = array_search($matched, $hashed, true);
            if ($index === false) {
                return false; // consumed by another request, or regenerated, since
            }

            unset($hashed[$index]);
            $fresh->two_factor_recovery_codes = encrypt(json_encode(array_values($hashed)));
            $fresh->save();

            // The instance of this request follows the row.
            $user->setAttribute('two_factor_recovery_codes', $fresh->two_factor_recovery_codes);
            $user->syncOriginalAttribute('two_factor_recovery_codes');

            return true;
        });
    }

    /**
     * Regenerate recovery codes for the given user.
     *
     * @return array{recovery_codes: list<string>}
     *
     * @throws \InvalidArgumentException if 2FA is not enabled
     */
    public function regenerateRecoveryCodes(Authenticatable $user): array
    {
        $this->requireModel($user);

        if (! $this->isEnabled($user)) {
            throw new \InvalidArgumentException('2FA is not enabled for this user.');
        }

        $recoveryCodes = $this->generateRecoveryCodes();
        $hashed = array_map(fn (string $c) => bcrypt($c), $recoveryCodes);

        $user->two_factor_recovery_codes = encrypt(json_encode($hashed));
        $user->save();

        return ['recovery_codes' => $recoveryCodes];
    }

    /**
     * Check whether 2FA is active for the given user.
     */
    public function isEnabled(Authenticatable $user): bool
    {
        $this->requireModel($user);

        return ! is_null($user->two_factor_confirmed_at ?? null);
    }

    /**
     * Ensure the given Authenticatable is also an Eloquent Model.
     *
     * All public methods in this service read and persist Eloquent-specific
     * properties (`save()`, dynamic attributes). A non-Model Authenticatable
     * would cause a fatal Error at the first property access, so we fail
     * fast with a clear message instead of relying on `assert()`, which is
     * compiled out when `zend.assertions = -1`.
     *
     * @throws \InvalidArgumentException if $user is not an Eloquent Model.
     *
     * @phpstan-assert Model $user
     */
    private function requireModel(Authenticatable $user): void
    {
        if (! $user instanceof Model) {
            throw new \InvalidArgumentException(
                'TwoFactorService requires an Eloquent Model user (Model&Authenticatable). '.
                'The provided Authenticatable is '.get_class($user).'.'
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Generate a cryptographically random Base32-encoded TOTP secret.
     *
     * @return string 20-byte (160-bit) secret encoded as Base32.
     */
    private function generateSecret(): string
    {
        $bytes = random_bytes(20);

        return $this->base32Encode($bytes);
    }

    /**
     * Generate N random plain-text recovery codes.
     *
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        $count = (int) config('martis.profile.two_factor.recovery_codes', 8);
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtolower(Str::random(10).'-'.Str::random(10));
        }

        return $codes;
    }

    /**
     * Verify a TOTP code against a Base32 secret (pure, no side effects).
     *
     * @param  string  $secret  Base32-encoded TOTP secret.
     * @param  string  $code  6-digit OTP to verify.
     */
    private function verify(string $secret, string $code): bool
    {
        if (strlen($code) !== 6 || ! ctype_digit($code)) {
            return false;
        }

        $key = $this->base32Decode($secret);
        $timestamp = $this->currentStep();

        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            if ($this->hotp($key, $timestamp + $i) === $code) {
                return true;
            }
        }

        return false;
    }

    /** The TOTP time step now (30 seconds each). */
    private function currentStep(): int
    {
        return (int) floor(now()->getTimestamp() / 30);
    }

    /**
     * Verify a TOTP code with replay protection.
     *
     * Records the time step a code was accepted for in
     * `two_factor_last_used_at`, stored as the start time of that step
     * (`step * 30`) in UTC, and rejects any step at or before it. A code accepted
     * for the step after the current one (a client clock running ahead, a
     * code seen before it was current) therefore cannot be used again while
     * that step is current, which a recorded wall-clock time allowed.
     *
     * The step is consumed with one conditional update (`WHERE
     * two_factor_last_used_at IS NULL OR two_factor_last_used_at < step`):
     * when two requests carry the same code at once, both read the old
     * value and both find the code valid, but only one update changes a
     * row, and a request whose update changes none fails. Any error while
     * recording the step fails the code too: a replay guard that cannot
     * record is not one to authenticate through.
     *
     * Requires the user model to have a `two_factor_last_used_at` column
     * (timestamp, nullable). If the column is absent the check degrades to
     * plain TOTP verification and logs a warning.
     *
     * @param  Model&Authenticatable  $user  The authenticated user.
     * @param  string  $secret  Base32-encoded TOTP secret.
     * @param  string  $code  6-digit OTP to verify.
     */
    private function verifyAndTrack(Model&Authenticatable $user, string $secret, string $code): bool
    {
        if (strlen($code) !== 6 || ! ctype_digit($code)) {
            return false;
        }

        $key = $this->base32Decode($secret);
        $timestamp = $this->currentStep();

        // Probe the schema once per install. Older installs shipped the
        // 2FA migration without this column (it was added alongside replay
        // protection); on those rows any attempt to write the replay
        // timestamp throws a SQL error and the whole verify turns into a
        // generic "Invalid code". Skip replay tracking when the column is
        // missing so legitimate codes still authenticate, and say so.
        $hasColumn = $this->hasLastUsedColumn($user);
        if (! $hasColumn) {
            $this->warnReplayGuardIsOff($user);
        }

        $lastStep = $hasColumn ? $this->lastConsumedStep($user) : -PHP_INT_MAX;

        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $step = $timestamp + $i;

            // Reject any step that was already consumed
            if ($step <= $lastStep) {
                continue;
            }

            if (hash_equals($this->hotp($key, $step), $code)) {
                return ! $hasColumn || $this->consumeStep($user, $step);
            }
        }

        return false;
    }

    /**
     * The TOTP step the user's last accepted code was for, from the start
     * time `two_factor_last_used_at` holds; `-PHP_INT_MAX` for none. A value
     * written before v2.4.0 is the wall-clock time of the acceptance, which
     * falls in the step the code was used in: the next acceptance rewrites
     * it as a step start.
     */
    private function lastConsumedStep(Model $user): int
    {
        // The stored attribute, before any cast: a `datetime` cast would read
        // the UTC string as app-timezone time.
        $value = $user->getAttributes()['two_factor_last_used_at'] ?? null;

        if ($value instanceof \DateTimeInterface) {
            return (int) floor($value->getTimestamp() / 30);
        }

        if (is_string($value) && $value !== '') {
            try {
                return (int) floor(Carbon::parse($value, 'UTC')->getTimestamp() / 30);
            } catch (Throwable) {
                return -PHP_INT_MAX;
            }
        }

        return is_int($value) ? intdiv($value, 30) : -PHP_INT_MAX;
    }

    /**
     * Record `$step` as consumed, once: the update changes the row only while
     * no step at or after this one is recorded, and the answer is whether it
     * did.
     */
    private function consumeStep(Model $user, int $step): bool
    {
        $column = 'two_factor_last_used_at';
        // In UTC, never in the app timezone: a local wall-clock string is
        // ambiguous for the hour a daylight-saving change repeats, and the
        // `<` below would then refuse a newer step as an older one.
        $stored = Carbon::createFromTimestampUTC($step * 30)->format($user->getDateFormat());

        try {
            $changed = $user->newModelQuery()
                ->whereKey($user->getKey())
                ->where(fn ($query) => $query->whereNull($column)->orWhere($column, '<', $stored))
                ->update([$column => $stored]);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        if ($changed !== 1) {
            return false; // the code was accepted by another request first
        }

        // The instance of this request follows the row.
        $user->setAttribute($column, $stored);
        $user->syncOriginalAttribute($column);

        return true;
    }

    /**
     * Say, once per service instance and table, that the users table has no
     * `two_factor_last_used_at` column: codes are accepted without replay
     * protection until it is added.
     */
    private function warnReplayGuardIsOff(Model $user): void
    {
        $table = $user->getConnectionName().':'.$user->getTable();
        if (isset($this->warnedReplayGuardIsOff[$table])) {
            return;
        }
        $this->warnedReplayGuardIsOff[$table] = true;

        Log::warning('Martis: the two_factor_last_used_at column is missing from the users table, so a TOTP code can be used again within its time window. Run `php artisan martis:install --with-2fa` or add a nullable timestamp column named two_factor_last_used_at.', [
            'table' => $user->getTable(),
        ]);
    }

    /**
     * The recovery code hashes stored in an encrypted list.
     *
     * @return list<string>
     */
    private function storedRecoveryHashes(mixed $encrypted): array
    {
        $decoded = json_decode((string) decrypt($encrypted), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** Resolves once per process whether the users table carries the replay
     *  timestamp column introduced alongside {@see self::verifyAndTrack()}.
     *  Keyed by connection:table so different user models in the same process
     *  (e.g. multi-connection test suites) each get an independent result. */
    private function hasLastUsedColumn(Model $user): bool
    {
        $key = $user->getConnectionName().':'.$user->getTable();

        if (array_key_exists($key, $this->lastUsedColumnCache)) {
            return $this->lastUsedColumnCache[$key];
        }

        try {
            $this->lastUsedColumnCache[$key] = Schema::connection($user->getConnectionName())
                ->hasColumn($user->getTable(), 'two_factor_last_used_at');
        } catch (Throwable) {
            $this->lastUsedColumnCache[$key] = false;
        }

        return $this->lastUsedColumnCache[$key];
    }

    /**
     * Compute an HMAC-based OTP for a given counter value.
     *
     * @param  string  $key  Raw binary key.
     * @param  int  $counter  Time step counter.
     * @return string 6-digit OTP.
     */
    private function hotp(string $key, int $counter): string
    {
        $data = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $data, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % 1000000;

        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Encode a binary string as Base32 (RFC 4648).
     *
     * @param  string  $data  Raw binary data.
     * @return string Base32-encoded string.
     */
    private function base32Encode(string $data): string
    {
        $chars = self::BASE32_CHARS;
        $result = '';
        $buffer = 0;
        $bitsLeft = 0;

        foreach (str_split($data) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $result .= $chars[($buffer >> $bitsLeft) & 0x1F];
            }
        }

        if ($bitsLeft > 0) {
            $result .= $chars[($buffer << (5 - $bitsLeft)) & 0x1F];
        }

        return $result;
    }

    /**
     * Decode a Base32-encoded string to raw binary.
     *
     * @param  string  $data  Base32-encoded string.
     * @return string Raw binary data.
     */
    private function base32Decode(string $data): string
    {
        $chars = self::BASE32_CHARS;
        $data = strtoupper($data);
        $result = '';
        $buffer = 0;
        $bitsLeft = 0;

        foreach (str_split($data) as $char) {
            $pos = strpos($chars, $char);
            if ($pos === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $pos;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $result;
    }

    /**
     * Generate a minimal SVG QR code using endroid/qr-code.
     *
     * @param  string  $uri  The otpauth:// URI to encode.
     * @return string SVG markup.
     */
    private function generateQrSvg(string $uri): string
    {
        $qrCode = QrCode::create($uri)
            ->setSize(200)
            ->setMargin(10);

        $writer = new SvgWriter;
        $result = $writer->write($qrCode);

        return $result->getString();
    }
}
