<?php

namespace Martis\Fields;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * Password field.
 *
 * Hidden from index and detail views by default.
 * Hashes the value automatically before persisting.
 *
 * Martis differentials:
 *  - ⭐ `withStrengthMeter()` — opt-in zxcvbn-like strength indicator.
 *  - ⭐ Declarative complexity requirements — `minLength`, `requireUppercase`,
 *    `requireLowercase`, `requireNumber`, `requireSymbol`,
 *    `disallowCommonPasswords`. Each adds a backend validation rule AND is
 *    published to the frontend so the UI can render a live ✓/✗ checklist.
 *  - ⭐ `showRequirements()` — opt-in checklist rendered under the meter.
 *    The meter auto-aligns: when every requirement is satisfied, the strength
 *    score is clamped to at least "Good".
 */
class Password extends Field
{
    protected bool $strengthMeter = false;

    protected bool $showRequirements = false;

    protected ?int $minLength = null;

    protected bool $requireUppercase = false;

    protected bool $requireLowercase = false;

    protected bool $requireNumber = false;

    protected bool $requireSymbol = false;

    protected bool $disallowCommonPasswords = false;

    /**
     * The tiny inline list used for `disallowCommonPasswords()`. Mirrors the
     * UI-side `scorePassword()` penalty so the frontend and backend agree on
     * what counts as "common". Kept small on purpose — this is not a full
     * breach dictionary (use a dedicated validator for that).
     *
     * @var list<string>
     */
    protected const COMMON_PASSWORDS = [
        'password', 'qwerty', '12345', '12345678', '123456789',
        'letmein', 'admin', 'welcome', 'abc123', 'iloveyou',
    ];

    /** Create a password field, hidden from index and detail views. */
    public function __construct(string $attribute, string $label)
    {
        parent::__construct($attribute, $label);
        $this->showOnIndex = false;
        $this->showOnDetail = false;
    }

    public function type(): string
    {
        return 'password';
    }

    /**
     * ⭐ Show a strength meter next to the input (0–4 scale).
     * When paired with a `PasswordConfirmation` field, the meter is shared.
     */
    public function withStrengthMeter(bool $enabled = true): static
    {
        $this->strengthMeter = $enabled;

        return $this;
    }

    public function hasStrengthMeter(): bool
    {
        return $this->strengthMeter;
    }

    /** Require a minimum length (adds `min:N` validation + a checklist row). */
    public function minLength(int $length): static
    {
        $this->minLength = max(1, $length);

        return $this;
    }

    /** ⭐ Require at least one uppercase letter. */
    public function requireUppercase(bool $value = true): static
    {
        $this->requireUppercase = $value;

        return $this;
    }

    /** ⭐ Require at least one lowercase letter. */
    public function requireLowercase(bool $value = true): static
    {
        $this->requireLowercase = $value;

        return $this;
    }

    /** ⭐ Require at least one digit. */
    public function requireNumber(bool $value = true): static
    {
        $this->requireNumber = $value;

        return $this;
    }

    /** ⭐ Require at least one non-alphanumeric symbol. */
    public function requireSymbol(bool $value = true): static
    {
        $this->requireSymbol = $value;

        return $this;
    }

    /** ⭐ Reject a small inline list of notoriously common passwords. */
    public function disallowCommonPasswords(bool $value = true): static
    {
        $this->disallowCommonPasswords = $value;

        return $this;
    }

    /** ⭐ Render the requirements checklist under the strength meter. */
    public function showRequirements(bool $value = true): static
    {
        $this->showRequirements = $value;

        return $this;
    }

    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        return null;
    }

    public function fill(Model $model, mixed $value): void
    {
        if ($this->readonly) {
            return;
        }

        if ($value !== null && $value !== '') {
            $model->setAttribute($this->attribute, Hash::make($value));
        }
    }

    /**
     * Translate the declarative requirements into Laravel rules. Only applied
     * when the incoming value is non-empty — a password-leave-blank update
     * remains valid.
     */
    public function buildRules(?string $context = null): array
    {
        $rules = parent::buildRules($context);

        if ($this->minLength !== null) {
            $rules[] = 'min:'.$this->minLength;
        }
        if ($this->requireUppercase) {
            $rules[] = 'regex:/[A-Z]/';
        }
        if ($this->requireLowercase) {
            $rules[] = 'regex:/[a-z]/';
        }
        if ($this->requireNumber) {
            $rules[] = 'regex:/\d/';
        }
        if ($this->requireSymbol) {
            $rules[] = 'regex:/[^A-Za-z0-9]/';
        }
        if ($this->disallowCommonPasswords) {
            $blacklist = self::COMMON_PASSWORDS;
            $rules[] = function (string $attribute, mixed $value, Closure $fail) use ($blacklist): void {
                if (! is_string($value) || $value === '') {
                    return;
                }
                $lower = strtolower($value);
                foreach ($blacklist as $common) {
                    if (str_starts_with($lower, $common)) {
                        $fail(self::translate(
                            'martis::messages.password_req_common_fail',
                            ['attribute' => $attribute],
                            "The {$attribute} is too common."
                        ));

                        return;
                    }
                }
            };
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        $requirements = [];
        if ($this->minLength !== null) {
            $requirements['minLength'] = $this->minLength;
        }
        if ($this->requireUppercase) {
            $requirements['uppercase'] = true;
        }
        if ($this->requireLowercase) {
            $requirements['lowercase'] = true;
        }
        if ($this->requireNumber) {
            $requirements['number'] = true;
        }
        if ($this->requireSymbol) {
            $requirements['symbol'] = true;
        }
        if ($this->disallowCommonPasswords) {
            $requirements['noCommon'] = true;
        }

        return array_filter([
            'strengthMeter' => $this->strengthMeter ?: null,
            'showRequirements' => $this->showRequirements ?: null,
            'requirements' => $requirements === [] ? null : $requirements,
        ], fn ($v) => $v !== null);
    }

    /**
     * Resolve a translation with a hard-coded English fallback when the
     * translator binding is unavailable (unit tests outside the container).
     *
     * @param  array<string, string>  $replace
     */
    private static function translate(string $key, array $replace, string $fallback): string
    {
        try {
            $translated = trans($key, $replace);
        } catch (\Throwable) {
            return $fallback;
        }
        if (! is_string($translated) || $translated === $key) {
            return $fallback;
        }

        return $translated;
    }
}
