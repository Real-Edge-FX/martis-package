<?php

namespace Martis\Enums;

/**
 * Column-span presets for metric cards. Each case maps to a 12-column
 * grid slot. Callers can also pass a raw integer (1-12) to
 * {@see \Martis\Metrics\Metric::width()} for custom widths.
 */
enum MetricWidthPreset: string
{
    case OneThird = '1/3';
    case Half = '1/2';
    case TwoThirds = '2/3';
    case Full = 'full';

    /**
     * Translate the preset to a 12-column grid span.
     */
    public function toGridCols(): int
    {
        return match ($this) {
            self::OneThird => 4,
            self::Half => 6,
            self::TwoThirds => 8,
            self::Full => 12,
        };
    }
}
