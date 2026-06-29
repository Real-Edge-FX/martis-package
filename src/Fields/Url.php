<?php

namespace Martis\Fields;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Database\Eloquent\Model;

/**
 * URL field — renders clickable links on index/detail and a text input on forms.
 *
 * Contexts:
 *  - index: clickable link
 *  - detail: clickable link
 *  - create: editable URL input
 *  - update: editable URL input
 *
 * Link text can be customised via displayUsing() (inherited) or displayText() (static).
 * Computed values are supported via resolveUsing().
 */
class Url extends Field
{
    /** Custom display text for the link (static string). */
    protected ?string $displayText = null;

    /** {@inheritdoc} */
    public function type(): string
    {
        return 'url';
    }

    /**
     * Set a static display text for the URL link.
     *
     * For dynamic text based on the model, use the inherited displayUsing() callback.
     * This is a convenience method for simple static text.
     */
    public function displayText(string $text): static
    {
        $this->displayText = $text;

        return $this;
    }

    /**
     * Get display text.
     */
    public function getDisplayText(): ?string
    {
        return $this->displayText;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_filter([
            'displayText' => $this->displayText,
        ], fn ($v) => $v !== null);
    }

    /** {@inheritdoc} */
    public function buildRules(?string $context = null): array
    {
        // Replace Laravel's strict `url` rule with a closure that first
        // normalises the value — auto-prepending `http://` when no scheme
        // is present — and then runs FILTER_VALIDATE_URL. This keeps
        // the field forgiving for authors who paste bare domains.
        return array_merge(parent::buildRules($context), [
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === null || $value === '' || ! is_string($value)) {
                    return;
                }
                $normalised = self::normaliseUrl($value);

                $invalid = filter_var($normalised, FILTER_VALIDATE_URL) === false;

                // Defense-in-depth XSS guard: FILTER_VALIDATE_URL accepts
                // some `javascript://` / `data://` / `vbscript://` forms.
                // Restrict the stored scheme to http/https so a dangerous
                // scheme can never reach the database (and therefore never
                // be rendered as a clickable href). A normalised value
                // always carries an explicit scheme.
                if (! $invalid) {
                    $scheme = strtolower((string) parse_url($normalised, PHP_URL_SCHEME));
                    if (! in_array($scheme, ['http', 'https'], true)) {
                        $invalid = true;
                    }
                }

                if ($invalid) {
                    try {
                        $message = trans('validation.url', ['attribute' => $attribute]);
                    } catch (\Throwable) {
                        $message = "The {$attribute} field must be a valid URL.";
                    }
                    $fail(is_string($message) ? $message : "The {$attribute} field must be a valid URL.");
                }
            },
        ]);
    }

    /**
     * Normalise the stored URL: trim whitespace and auto-prepend `http://`
     * when the user typed a hostname without a scheme.
     */
    public static function normaliseUrl(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }
        if (preg_match('#^[a-z][a-z0-9+\-.]*://#i', $trimmed) === 1) {
            return $trimmed;
        }

        return 'http://'.$trimmed;
    }

    public function fill(Model $model, mixed $value): void
    {
        if (is_string($value)) {
            $value = self::normaliseUrl($value);
            if ($value === '') {
                $value = null;
            }
        }
        parent::fill($model, $value);
    }

    /** {@inheritdoc} */
    protected function defaultColumnWidth(): array
    {
        return ['maxWidth' => '280px', 'truncate' => true];
    }
}
