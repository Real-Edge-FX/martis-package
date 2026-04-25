<?php

namespace Martis\Fields;

use DateTimeZone;

/**
 * Timezone dropdown field — stores an IANA timezone identifier
 * (e.g. `Europe/Lisbon`, `America/New_York`).
 *
 * Martis differentials:
 *  - ⭐ Live current time — the React dropdown streams the current local
 *    time for each timezone option (ticks every 60s).
 *  - ⭐ Auto-detect button — reads `Intl.DateTimeFormat().resolvedOptions().timeZone`
 *    client-side and fills the field.
 *  - ⭐ Grouped by continent — optgroups (`Europe`, `America`, `Asia`, …)
 *    with search-by-city / search-by-offset in the dropdown.
 */
class Timezone extends Field
{
    public function type(): string
    {
        return 'timezone';
    }

    /**
     * List every IANA timezone PHP knows about, grouped by the first segment
     * of the identifier (`Europe`, `America`, `Africa`, `Asia`, …). Ungrouped
     * legacy zones like `UTC` fall under the `Other` group.
     *
     * @return array<string, list<string>>
     */
    public static function groupedList(): array
    {
        $grouped = [];
        foreach (DateTimeZone::listIdentifiers() as $tz) {
            $group = str_contains($tz, '/') ? strstr($tz, '/', true) : 'Other';
            $grouped[$group] ??= [];
            $grouped[$group][] = $tz;
        }
        ksort($grouped);
        foreach ($grouped as $g => $zones) {
            sort($zones);
            $grouped[$g] = $zones;
        }

        return $grouped;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [
            'options' => self::groupedList(),
        ];
    }
}
