<?php

namespace Martis\Enums;

/**
 * Visual shape used by the Avatar and UiAvatar fields.
 *
 * - `Circle`  — perfectly round; matches Nova's `rounded()` default.
 * - `Rounded` — soft-rounded square (8px radius).
 * - `Squared` — right-angled square.
 */
enum AvatarShape: string
{
    case Circle = 'circle';
    case Rounded = 'rounded';
    case Squared = 'squared';
}
