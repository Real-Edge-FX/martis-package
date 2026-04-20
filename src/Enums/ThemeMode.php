<?php

namespace Martis\Enums;

/**
 * Theme modes supported by the Martis design system.
 *
 * `System` follows `prefers-color-scheme` at runtime; Dark/Light force
 * the choice regardless of OS settings.
 */
enum ThemeMode: string
{
    case Dark = 'dark';
    case Light = 'light';
    case System = 'system';
}
