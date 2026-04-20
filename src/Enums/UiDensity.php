<?php

namespace Martis\Enums;

/**
 * UI density tokens. Sets `html[data-density]` which reassigns the
 * `--martis-row-h / nav-item-h / input-h / btn-h / pad-x / pad-y / gap`
 * scaffolding across the application.
 *
 * Per-surface overrides (⭐ D3) are applied via `data-density` on any
 * ancestor element; the closest ancestor wins.
 */
enum UiDensity: string
{
    case Comfortable = 'comfortable';
    case Dense = 'dense';
}
