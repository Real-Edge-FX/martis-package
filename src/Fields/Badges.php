<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;

/**
 * Badges field: one value drawn as several badge pills.
 *
 * Contexts:
 *  - index: yes (display-only)
 *  - detail: yes (display-only)
 *  - create: no (hidden by default, not an editable input)
 *  - update: no (hidden by default, not an editable input)
 *
 * The resolved value is a list of items. Each item is either a plain string
 * (label only, default type) or an array `['label' => string, 'type' => ?string,
 * 'tooltip' => ?string]`. `type` uses the vocabulary of {@see Badge}: a built-in
 * type (info, success, warning, danger), a key of `types()`/`addTypes()`, a hex
 * colour, or a value translated through `map()`.
 *
 * ```php
 * Badges::make('languages', 'Languages')
 *     ->resolveUsing(fn ($value, $model) => [
 *         ['label' => 'EN', 'type' => 'success'],
 *         ['label' => 'Missing PT', 'type' => 'warning', 'tooltip' => 'No translation yet'],
 *         'FR',
 *     ]);
 * ```
 *
 * An item that is neither a string nor an array with a string `label` throws an
 * `InvalidArgumentException` naming the field attribute. Labels render as plain
 * text. The per-row `resolveBadgeUsing()`, `labels()` and `icons()` hooks of
 * {@see Badge} do not apply: each item carries its own label.
 */
class Badges extends Badge
{
    /** {@inheritdoc} */
    public function type(): string
    {
        return 'badges';
    }

    /**
     * Normalise the resolved value to a list of `{label, type, tooltip}` items.
     *
     * @return list<array{label: string, type: ?string, tooltip: ?string}>
     */
    public function resolveForDisplay(Model $model, ?string $attribute = null): mixed
    {
        $value = Field::resolveForDisplay($model, $attribute);

        return $this->normalizeItems($value, $attribute ?? $this->attribute);
    }

    /**
     * @return list<array{label: string, type: ?string, tooltip: ?string}>
     */
    private function normalizeItems(mixed $value, string $attribute): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_iterable($value)) {
            throw new \InvalidArgumentException(
                "Badges field [{$attribute}] must resolve to a list of badge items, got ".get_debug_type($value).'.',
            );
        }

        $items = [];
        foreach ($value as $index => $item) {
            if (is_string($item)) {
                $items[] = ['label' => $item, 'type' => null, 'tooltip' => null];

                continue;
            }

            if (! is_array($item) || ! isset($item['label']) || ! is_string($item['label'])) {
                throw new \InvalidArgumentException(
                    "Badges field [{$attribute}] item {$index} must be a string or an array with a string 'label', got "
                    .(is_array($item) ? 'an array without a string label' : get_debug_type($item)).'.',
                );
            }

            foreach (['type', 'tooltip'] as $key) {
                if (isset($item[$key]) && ! is_string($item[$key])) {
                    throw new \InvalidArgumentException(
                        "Badges field [{$attribute}] item {$index} '{$key}' must be a string, got ".get_debug_type($item[$key]).'.',
                    );
                }
            }

            $items[] = [
                'label' => $item['label'],
                'type' => $item['type'] ?? null,
                'tooltip' => $item['tooltip'] ?? null,
            ];
        }

        return $items;
    }
}
