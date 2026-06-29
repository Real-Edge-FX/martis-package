<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * BooleanGroup — map of named boolean flags stored as JSON.
 *
 * The stored value is an associative array `['key' => bool, ...]`; the UI
 * renders one checkbox per option with its translated label.
 *
 * ⭐ Martis differentials:
 *  - `grouped([section => keys])` — organise options into collapsible
 *    sections so long flag lists (permissions, feature gates) stay
 *    manageable.
 *  - `minChecked(int)` / `maxChecked(int)` — validation constraints
 *    enforced on the backend AND surfaced as a live counter in the UI.
 *  - `requireAll()` / `requireAny()` convenience presets that compile
 *    down to the min/max constraints.
 *
 * Serialised to the frontend as `{ type: 'boolean_group', options,
 * labels, groups, hideFalseValues, hideTrueValues, noValueText,
 * minChecked, maxChecked }`.
 */
class BooleanGroup extends Field
{
    /** @var array<string, string> option key → raw label */
    protected array $options = [];

    /**
     * Lazy resolver — set when `options()` was called with a Closure
     * instead of an array. The closure runs at schema-render time so
     * the available flags can be computed from the DB / config /
     * current user.
     */
    protected ?\Closure $optionsResolver = null;

    /** @var array<string, string> option key → translated label (overrides raw) */
    protected array $labels = [];

    /** @var array<string, list<string>> section title → list of option keys (⭐ differential) */
    protected array $groups = [];

    protected bool $hideFalseValues = false;

    protected bool $hideTrueValues = false;

    protected ?string $noValueText = null;

    /** @var int|null ⭐ minimum checked count (inclusive) */
    protected ?int $minChecked = null;

    /** @var int|null ⭐ maximum checked count (inclusive) */
    protected ?int $maxChecked = null;

    public function type(): string
    {
        return 'boolean_group';
    }

    /**
     * Register the available flags.
     *
     * Accepts either a static `[key => label]` array or a Closure that
     * receives the active `Request` and returns one. The closure form
     * is evaluated lazily via `getOptions()` so the flags can pull
     * from the DB, config or the authenticated user's permissions.
     *
     * @param  array<string, string>|\Closure(Request|null): array<string, string>  $options
     */
    public function options(array|\Closure $options): static
    {
        if ($options instanceof \Closure) {
            $this->optionsResolver = $options;
            $this->options = [];

            return $this;
        }

        $this->optionsResolver = null;
        $this->options = $options;

        return $this;
    }

    /**
     * Override the raw option labels with translated versions.
     *
     * @param  array<string, string>  $labels
     */
    public function labels(array $labels): static
    {
        $this->labels = $labels;

        return $this;
    }

    /**
     * ⭐ Martis differential — organise options into named sections.
     * Rendered as collapsible panels in the UI.
     *
     * @param  array<string, list<string>>  $groups  section label → list of option keys
     */
    public function grouped(array $groups): static
    {
        $this->groups = $groups;

        return $this;
    }

    public function hideFalseValues(bool $value = true): static
    {
        $this->hideFalseValues = $value;

        return $this;
    }

    public function hideTrueValues(bool $value = true): static
    {
        $this->hideTrueValues = $value;

        return $this;
    }

    public function noValueText(string $text): static
    {
        $this->noValueText = $text;

        return $this;
    }

    /** ⭐ Martis differential — enforce a minimum number of checked flags. */
    public function minChecked(int $count): static
    {
        $this->minChecked = max(0, $count);

        return $this;
    }

    /** ⭐ Martis differential — cap the maximum number of checked flags. */
    public function maxChecked(int $count): static
    {
        $this->maxChecked = max(0, $count);

        return $this;
    }

    /** ⭐ Sugar for `minChecked(count(options))`. Forces every flag on. */
    public function requireAll(): static
    {
        return $this->minChecked(count($this->getOptions()));
    }

    /** ⭐ Sugar for `minChecked(1)`. Forces at least one flag on. */
    public function requireAny(): static
    {
        return $this->minChecked(1);
    }

    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $raw = parent::resolve($model, $attribute);

        if ($raw === null || $raw === '') {
            return $this->emptyPayload();
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->normalise($decoded);
            }
        }
        if (is_array($raw)) {
            return $this->normalise($raw);
        }

        return $this->emptyPayload();
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_filter([
            'options' => $this->getOptions(),
            'labels' => $this->labels !== [] ? $this->labels : null,
            'groups' => $this->groups !== [] ? $this->groups : null,
            'hideFalseValues' => $this->hideFalseValues ?: null,
            'hideTrueValues' => $this->hideTrueValues ?: null,
            'noValueText' => $this->noValueText,
            'minChecked' => $this->minChecked,
            'maxChecked' => $this->maxChecked,
        ], fn (mixed $v): bool => $v !== null);
    }

    /** @return array<string, string> */
    public function getOptions(): array
    {
        if ($this->optionsResolver !== null) {
            $request = $this->safeRequest();
            $resolved = ($this->optionsResolver)($request);

            return is_array($resolved) ? $resolved : [];
        }

        return $this->options;
    }

    /** @return array<string, list<string>> */
    public function getGroups(): array
    {
        return $this->groups;
    }

    public function getMinChecked(): ?int
    {
        return $this->minChecked;
    }

    public function getMaxChecked(): ?int
    {
        return $this->maxChecked;
    }

    /**
     * Translate a validation rule message with the package's `martis::`
     * namespace, falling back to a static English string when the
     * Laravel translator is not booted (unit tests, raw CLI).
     *
     * @param  array<string, mixed>  $replace
     */
    protected static function translateRuleMessage(string $key, string $fallback, array $replace = []): string
    {
        try {
            if (function_exists('app') && app()->bound('translator')) {
                $translated = __("martis::messages.{$key}", $replace);
                if (is_string($translated) && $translated !== "martis::messages.{$key}") {
                    return $translated;
                }
            }
        } catch (\Throwable) {
            // Translator missing — drop to fallback.
        }

        return $fallback;
    }

    /**
     * {@inheritdoc}
     *
     * Adds a closure rule that enforces `minChecked` / `maxChecked` on
     * the backend. Without this, the frontend was the only place
     * counting selected flags, and a tampered payload would silently
     * skip the constraint. The closure stays in sync with `getOptions()`
     * so closure-based option lists also validate correctly.
     */
    public function buildRules(?string $context = null): array
    {
        $rules = parent::buildRules($context);

        if ($this->minChecked === null && $this->maxChecked === null) {
            return $rules;
        }

        $min = $this->minChecked;
        $max = $this->maxChecked;
        $label = $this->label;

        $rules[] = function (string $attribute, mixed $value, \Closure $fail) use ($min, $max, $label): void {
            if (! is_array($value)) {
                $value = [];
            }
            $checked = count(array_filter($value, static fn ($v) => $v === true || $v === 1 || $v === '1' || $v === 'true'));

            if ($min !== null && $checked < $min) {
                $fail(self::translateRuleMessage(
                    'boolean_group_min_checked',
                    "{$label} requires at least {$min} checked option(s); only {$checked} are checked.",
                    ['attribute' => $label, 'min' => $min, 'count' => $checked],
                ));
            }
            if ($max !== null && $checked > $max) {
                $fail(self::translateRuleMessage(
                    'boolean_group_max_checked',
                    "{$label} allows at most {$max} checked option(s); {$checked} are checked.",
                    ['attribute' => $label, 'max' => $max, 'count' => $checked],
                ));
            }
        };

        return $rules;
    }

    /** @return array<string, bool> */
    private function emptyPayload(): array
    {
        $out = [];
        foreach ($this->getOptions() as $key => $_) {
            $out[$key] = false;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, bool>
     */
    private function normalise(array $raw): array
    {
        $out = [];
        foreach ($this->getOptions() as $key => $_) {
            $out[$key] = (bool) ($raw[$key] ?? false);
        }

        return $out;
    }
}
