<?php

namespace Martis\Fields;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Contracts\FieldContract;

/**
 * Base class for rows inside a {@see Repeater}.
 *
 * Subclasses declare the field set via {@see self::fields()} and, when the
 * parent repeater is in HasMany mode, a target Eloquent model via the
 * static {@see self::$model} property.
 *
 * ⭐ Martis differentials:
 *  - Row header is **fully configurable**: icon, accent color, live title
 *    template resolved per row, and a badge with the 1-based row index.
 *  - `uniqueKey()` / `shortName()` are cached on the instance so subclasses
 *    can freely override without the Repeater having to introspect.
 */
abstract class Repeatable
{
    /**
     * Target model for HasMany storage.
     *
     * @var class-string<Model>|null
     */
    public static ?string $model = null;

    /** Phosphor icon shown on the row header. ⭐ Martis differential. */
    protected ?string $icon = null;

    /** Semantic color token for the row header accent. ⭐ Martis differential. */
    protected ?string $color = null;

    /**
     * Title resolver for the row header.
     *
     * Accepts a Closure (`fn (array $rowValues, int $index) => string`) or a
     * template string using `{attribute}` placeholders (e.g. `'{name}'`).
     * ⭐ Martis differential.
     */
    protected Closure|string|null $title = null;

    /** Render a "#N" badge on each row header. ⭐ Martis differential. */
    protected bool $badgeCount = false;

    public static function make(): static
    {
        return new static;
    }

    /**
     * Field set that each row renders.
     *
     * @return array<int, FieldContract>
     */
    abstract public function fields(Request $request): array;

    /**
     * Stable short name used as the row type discriminator in the payload.
     *
     * Defaults to `Str::kebab(class_basename(static::class))` so the
     * frontend can identify the row type without exposing the raw FQCN.
     * Subclasses can override to freeze a specific name when the PHP
     * class is renamed.
     */
    public function shortName(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    /**
     * Human-readable label used in the multi-type "Add" menu and the row
     * header fallback. Defaults to the class basename.
     */
    public function label(): string
    {
        return Str::title(str_replace('_', ' ', Str::snake(class_basename(static::class))));
    }

    /**
     * Unique key used by the frontend to group rows of the same type.
     * Uses the stable short name so renames don't invalidate existing
     * payloads as long as subclasses pin their shortName.
     */
    public function uniqueKey(): string
    {
        return $this->shortName();
    }

    /**
     * ⭐ Martis differential — Phosphor icon rendered in the row header.
     */
    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * ⭐ Martis differential — semantic color token ('info', 'success',
     * 'warning', 'danger', 'muted', 'accent') painted as a left accent
     * border on the row header.
     */
    public function color(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    /**
     * ⭐ Martis differential — dynamic title per row.
     *
     * Accepts either a template string with `{attribute}` placeholders
     * (e.g. `'{quantity}× {description}'`) resolved on the frontend, or a
     * Closure that receives the current row values and its 1-based index.
     *
     * @param  Closure|string  $title  `fn (array $rowValues, int $index): string` or template
     */
    public function title(Closure|string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /** ⭐ Martis differential — show a "#N" badge on each row header. */
    public function badgeCount(bool $enabled = true): static
    {
        $this->badgeCount = $enabled;

        return $this;
    }

    /**
     * Resolve the title for a specific row (called when the title is a
     * Closure; the frontend handles template strings on its own).
     *
     * @param  array<string, mixed>  $rowValues
     */
    public function resolveTitle(array $rowValues, int $index): ?string
    {
        if ($this->title instanceof Closure) {
            $resolved = ($this->title)($rowValues, $index);

            return $resolved === null ? null : (string) $resolved;
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $fields = array_map(
            fn (FieldContract $f) => $f->toArray(),
            $this->fields($request),
        );

        return [
            'shortName' => $this->shortName(),
            'uniqueKey' => $this->uniqueKey(),
            'label' => $this->label(),
            'model' => static::$model,
            'icon' => $this->icon,
            'color' => $this->color,
            'titleTemplate' => is_string($this->title) ? $this->title : null,
            'hasTitleCallback' => $this->title instanceof Closure,
            'badgeCount' => $this->badgeCount,
            'fields' => $fields,
        ];
    }
}
