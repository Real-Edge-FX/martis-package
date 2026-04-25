<?php

namespace Martis\Profile;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
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
     * Generate a new TOTP secret and return setup data.
     *
     * @return array{secret: string, qr_code_svg: string, otpauth_uri: string}
     */
    public function generateSetup(Authenticatable $user): array
    {
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
        assert($user instanceof Model);
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
        assert($user instanceof Model);
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
        assert($user instanceof Model);
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
        assert($user instanceof Model);
        if (! $user->two_factor_secret || ! $user->two_factor_confirmed_at) {
            return false;
        }

        $secret = (string) decrypt($user->two_factor_secret);

        return $this->verifyAndTrack($user, $secret, $code);
    }

    /**
     * Verify a recovery code against the user's stored hashed codes.
     *
     * If valid, the code is consumed (removed from the list).
     */
    public function verifyRecoveryCode(Authenticatable $user, string $code): bool
    {
        assert($user instanceof Model);
        if (! $user->two_factor_recovery_codes) {
            return false;
        }

        /** @var list<string> $hashed */
        $hashed = (array) json_decode((string) decrypt($user->two_factor_recovery_codes), true);

        foreach ($hashed as $index => $hash) {
            if (password_verify($code, $hash)) {
                // Consume the recovery code
                unset($hashed[$index]);
                $user->two_factor_recovery_codes = encrypt(json_encode(array_values($hashed)));
                $user->save();

                return true;
            }
        }

        return false;
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
        assert($user instanceof Model);

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
        assert($user instanceof Model);

        return ! is_null($user->two_factor_confirmed_at ?? null);
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
        $timestamp = (int) floor(time() / 30);

        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            if ($this->hotp($key, $timestamp + $i) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify a TOTP code with replay protection.
     *
     * Tracks the last successfully used time step in .
     * Rejects codes from time steps at or before the last successful step,
     * preventing replay attacks within the ±WINDOW interval (~90 seconds).
     *
     * Requires the user model to have a  column
     * (timestamp, nullable). If the column is absent the check degrades
     * gracefully to plain TOTP verification.
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
        $timestamp = (int) floor(time() / 30);

        // Probe the schema once per install. Older installs shipped the
        // 2FA migration without this column (it was added alongside replay
        // protection); on those rows any attempt to `save()` the replay
        // timestamp throws a SQL error and the whole verify turns into a
        // generic "Invalid code". Skip replay tracking when the column is
        // missing so legitimate codes still authenticate.
        $hasColumn = $this->hasLastUsedColumn($user);

        // Determine the last used time step.
        $lastUsedAt = $hasColumn ? ($user->two_factor_last_used_at ?? null) : null;

        $lastStep = $lastUsedAt instanceof \DateTimeInterface
            ? (int) floor($lastUsedAt->getTimestamp() / 30)
            : -PHP_INT_MAX;

        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $step = $timestamp + $i;

            // Reject any step that was already consumed
            if ($step <= $lastStep) {
                continue;
            }

            if ($this->hotp($key, $step) === $code) {
                if ($hasColumn) {
                    try {
                        $user->two_factor_last_used_at = now();
                        $user->save();
                    } catch (Throwable) {
                        // Swallow persistence errors — we prefer letting the
                        // user authenticate over denying a valid code when
                        // the replay-tracking column is absent or the row is
                        // momentarily locked.
                    }
                }

                return true;
            }
        }

        return false;
    }

    /** Resolves once per request whether the users table carries the replay
     *  timestamp column introduced alongside {@see self::verifyAndTrack()}. */
    private function hasLastUsedColumn(Model $user): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $cached = \Illuminate\Support\Facades\Schema::connection($user->getConnectionName())
                ->hasColumn($user->getTable(), 'two_factor_last_used_at');
        } catch (Throwable) {
            $cached = false;
        }

        return $cached;
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
