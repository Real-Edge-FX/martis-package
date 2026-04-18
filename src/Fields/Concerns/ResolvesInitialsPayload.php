<?php

namespace Martis\Fields\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared trait for fields that render "initials-in-a-coloured-circle"
 * (Avatar's default fallback, UiAvatar). Keeps the palette + initials
 * computation in one place so the two fields — and the topbar, profile,
 * login surfaces — always agree on what letters and colour a given name
 * ends up with.
 *
 * Consumers provide:
 *   - A seed attribute (e.g. 'name') — the source of both initials and
 *     the deterministic palette slot.
 *   - Optional `colorFrom` attribute (e.g. 'brand_color') — when the
 *     model carries an explicit colour, it overrides the palette.
 *   - Optional `initialsCallback` — custom `(seed, model) => string`.
 */
trait ResolvesInitialsPayload
{
    /** @var list<string> 16-slot palette used when no colourFrom attribute is set. */
    protected static array $initialsPalette = [
        '#2563eb', '#7c3aed', '#db2777', '#dc2626',
        '#ea580c', '#ca8a04', '#16a34a', '#0d9488',
        '#0891b2', '#4f46e5', '#c026d3', '#9333ea',
        '#e11d48', '#059669', '#0284c7', '#475569',
    ];

    /**
     * Build the `{ initials, color, seed }` payload the frontend uses
     * to render a coloured circle with letters inline — no external
     * service call.
     *
     * @return array{initials: string, color: string, seed: string}
     */
    protected function initialsPayload(
        Model $model,
        string $seedAttribute,
        ?string $colorFromAttribute = null,
        ?Closure $initialsCallback = null,
    ): array {
        $seed = (string) ($model->getAttribute($seedAttribute) ?? '');

        return [
            'initials' => $this->computeInitials($seed, $model, $initialsCallback),
            'color' => $this->resolveInitialsColor($seed, $model, $colorFromAttribute),
            'seed' => $seed,
        ];
    }

    protected function computeInitials(string $seed, Model $model, ?Closure $callback = null): string
    {
        if ($callback !== null) {
            $result = $callback($seed, $model);

            return is_string($result) ? mb_strtoupper(mb_substr($result, 0, 3)) : '';
        }

        $trimmed = trim($seed);
        if ($trimmed === '') {
            return '';
        }

        $tokens = preg_split('/\s+/u', $trimmed) ?: [];
        $first = mb_substr($tokens[0] ?? '', 0, 1);
        $last = count($tokens) > 1 ? mb_substr($tokens[count($tokens) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    protected function resolveInitialsColor(string $seed, Model $model, ?string $colorFromAttribute = null): string
    {
        if ($colorFromAttribute !== null) {
            $custom = $model->getAttribute($colorFromAttribute);
            if (is_string($custom) && $custom !== '') {
                return $custom;
            }
        }

        return $this->deterministicInitialsColor($seed);
    }

    /**
     * Stable 16-slot palette derived from the seed string. Same seed
     * always yields the same colour across requests, migrations and
     * even environments — no DB column required.
     */
    protected function deterministicInitialsColor(string $seed): string
    {
        if ($seed === '') {
            return self::$initialsPalette[0];
        }

        $hash = 0;
        $bytes = unpack('C*', $seed) ?: [];
        foreach ($bytes as $byte) {
            $hash = (($hash << 5) - $hash + $byte) & 0xFFFFFFFF;
        }

        return self::$initialsPalette[abs($hash) % count(self::$initialsPalette)];
    }
}
