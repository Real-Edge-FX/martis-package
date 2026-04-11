<?php

namespace Martis\Enums;

/**
 * Controls the horizontal width of the peek/preview hover card in BelongsTo fields.
 *
 * Usage:
 *   BelongsTo::make('author')->peekSize(PeekSize::LG)
 */
enum PeekSize: string
{
    case XS = 'xs';   // 8rem  (128px)
    case SM = 'sm';   // 12rem (192px)
    case MD = 'md';   // 16rem (256px) — default
    case LG = 'lg';   // 22rem (352px)
    case XL = 'xl';   // 28rem (448px)

    /**
     * CSS max-width value for this size.
     */
    public function maxWidth(): string
    {
        return match ($this) {
            self::XS => '8rem',
            self::SM => '12rem',
            self::MD => '16rem',
            self::LG => '22rem',
            self::XL => '28rem',
        };
    }
}
