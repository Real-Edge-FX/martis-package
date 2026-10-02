<?php

namespace Martis\Fields;

use ArrayObject;
use Illuminate\Contracts\Support\Arrayable;
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

    /** {@inheritdoc} */
    public function hasStructuredValue(): bool
    {
        return true;
    }

    /**
     * Write the flag map the user may set.
     *
     * A JSON string (the shape the multipart request path carries, and what
     * `resolve()` already reads) is decoded to the map first. The map is then
     * projected onto the flags `getOptions()` offers the user: a submitted
     * key the options do not name is ignored, each offered value becomes a
     * boolean (an offered flag the submission leaves out is off), and the
     * stored flags the user was not offered (an `options()` closure scoped to
     * the user) keep their stored value, so an editor can neither switch on a
     * flag they were not shown nor erase one an administrator set.
     *
     * An empty value (`null`, `''`) switches the offered flags off: the
     * attribute becomes `null` when no stored flag is left to keep.
     */
    public function fill(Model $model, mixed $value): void
    {
        if ($this->isReadonly()) {
            return;
        }

        $empty = $value === null || $value === '';

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        $submitted = is_array($value) ? $value : [];
        $offered = array_map('strval', array_keys($this->getOptions()));

        if ($this->fillCallback !== null) {
            ($this->fillCallback)($model, $empty ? $value : $this->offeredFlags($submitted, $offered), $this->attribute, $this->safeRequest());

            return;
        }

        // A computed field has no backing attribute to write (see Field::fill()).
        if ($this->computed) {
            return;
        }

        // A value that is not a map (the controllers reject one before it
        // gets here) writes nothing but the empty one, which clears.
        if (! $empty && ! is_array($value)) {
            return;
        }

        $flags = $this->storedFlags($model);
        foreach ($this->offeredFlags($submitted, $offered) as $key => $enabled) {
            $flags[$key] = $enabled;
        }

        if ($empty) {
            // Clearing switches the offered flags off; only the flags the
            // user cannot see stay.
            $flags = array_diff_key($flags, array_flip($offered));
        }

        $model->setAttribute(
            $this->attribute,
            $this->storableStructuredValue($model, $this->attribute, $flags === [] ? null : $flags),
        );
    }

    /**
     * The submitted flags the field offers, each as a boolean, in the order
     * of the options; an offered flag the submission leaves out is off.
     *
     * @param  array<array-key, mixed>  $submitted
     * @param  list<string>  $offered
     * @return array<string, bool>
     */
    private function offeredFlags(array $submitted, array $offered): array
    {
        $flags = [];
        foreach ($offered as $key) {
            $raw = $submitted[$key] ?? false;
            $flags[$key] = is_scalar($raw) && filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        }

        return $flags;
    }

    /**
     * The flags the record stores now, as a map (a JSON string, an array or
     * the object a cast hands back are all read), or none.
     *
     * @return array<array-key, mixed>
     */
    private function storedFlags(Model $model): array
    {
        $raw = $model->getAttribute($this->attribute);

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if ($raw instanceof Arrayable) {
            $raw = $raw->toArray();
        } elseif ($raw instanceof ArrayObject) {
            $raw = $raw->getArrayCopy();
        }

        return is_array($raw) ? $raw : [];
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
