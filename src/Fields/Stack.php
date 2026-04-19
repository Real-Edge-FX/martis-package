<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Stack — composite display field that renders a vertical stack of
 * {@see Line} fields as a single cell/detail slot.
 *
 * Laravel Nova v5 parity: Stack field.
 * Reference: https://nova.laravel.com/docs/v5/resources/fields#stack-field
 *
 * ⭐ Martis differentials:
 *  - **Visible on the index table** too — Nova's Stack is detail-only.
 *    Useful for compressing multi-line identity cells (name + email +
 *    company) into a single column without a custom component.
 *  - **`divider()`** — inserts a visual separator between the rendered
 *    Lines with configurable colour/width. Inline style is skipped when
 *    set — the frontend renders a thin rule using `--martis-border`.
 *  - Works seamlessly with {@see Line::subtitleFrom()} so each row can
 *    emit 2x as many lines as declared without extra verbosity.
 *
 * Example:
 *   Stack::make('identity', [
 *       Line::make('name')->asHeading()->subtitleFrom('company'),
 *       Line::make('email')->asMuted(),
 *   ])->divider();
 */
class Stack extends Field
{
    /** @var list<Line> */
    protected array $lines;

    protected bool $divider = false;

    /**
     * @param  list<Line>  $lines
     */
    public function __construct(string $attribute, string $label, array $lines = [])
    {
        parent::__construct($attribute, $label);
        $this->lines = array_values(array_filter($lines, fn ($l) => $l instanceof Line));
    }

    public static function make(string $attribute, array|string|null $linesOrLabel = null, array $lines = []): static
    {
        $default = Str::title(str_replace('_', ' ', $attribute));

        // Support both calling styles:
        //   Stack::make('attr', [Line, Line])
        //   Stack::make('attr', 'Label', [Line, Line])
        if (is_array($linesOrLabel)) {
            $instance = new static($attribute, $default, $linesOrLabel);
        } else {
            $instance = new static($attribute, $linesOrLabel ?? $default, $lines);
        }

        return $instance->hideFromForms();
    }

    public function type(): string
    {
        return 'stack';
    }

    /**
     * ⭐ Martis differential — render a thin separator between Lines.
     */
    public function divider(bool $enabled = true): static
    {
        $this->divider = $enabled;

        return $this;
    }

    public function hasDivider(): bool
    {
        return $this->divider;
    }

    /**
     * @return list<Line>
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    /**
     * Resolve every line per row so the frontend receives a flat payload
     * with `{ text, variant, subtitle }` per entry. This is what lets
     * the Stack render on the INDEX table cell as a multi-line snippet.
     */
    public function resolveForDisplay(Model $model, ?string $attribute = null): mixed
    {
        $entries = [];
        foreach ($this->lines as $line) {
            $entries[] = [
                'text' => $this->toText($line->resolveForDisplay($model)),
                'variant' => $line->getVariant()->value,
                'subtitle' => $line->resolveSubtitle($model),
            ];
        }

        return [
            '__martisStack' => true,
            'entries' => $entries,
            'divider' => $this->divider,
        ];
    }

    /**
     * Serialised schema includes the child line definitions so the
     * frontend knows the variants ahead of time (used when the resolver
     * hasn't run yet, e.g. inside inline create previews).
     *
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [
            'divider' => $this->divider,
            'lines' => array_map(
                fn (Line $l) => $l->toArray(),
                $this->lines,
            ),
        ];
    }

    private function toText(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
