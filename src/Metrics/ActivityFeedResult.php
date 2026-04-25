<?php

namespace Martis\Metrics;

/**
 * Result for an ActivityFeed metric — ordered list of recent events with
 * an actor, verb, optional target reference, timestamp and a coloured
 * Phosphor icon for the leading avatar tile.
 */
class ActivityFeedResult extends MetricResult
{
    /** @var array<int, array<string, mixed>> */
    protected array $items = [];

    /**
     * Add an event to the feed.
     *
     * @param  string  $actor   Name displayed in bold at the start of the line.
     * @param  string  $verb    Description after the actor (muted).
     * @param  string  $time    Relative timestamp string ("2m ago").
     * @param  string|null  $target  Optional target identifier rendered in mono.
     * @param  string|null  $icon    Phosphor icon name for the avatar square.
     * @param  string|null  $color   CSS colour / token for the avatar tile.
     */
    public function add(
        string $actor,
        string $verb,
        string $time,
        ?string $target = null,
        ?string $icon = null,
        ?string $color = null,
    ): static {
        $item = [
            'actor' => $actor,
            'verb' => $verb,
            'time' => $time,
        ];

        if ($target !== null) {
            $item['target'] = $target;
        }
        if ($icon !== null) {
            $item['icon'] = $icon;
        }
        if ($color !== null) {
            $item['color'] = $color;
        }

        $this->items[] = $item;

        return $this;
    }

    /**
     * Bulk set items — each row must match the {@see add()} shape.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function items(array $items): static
    {
        $this->items = $items;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'items' => $this->items,
        ];
    }
}
