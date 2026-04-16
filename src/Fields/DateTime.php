<?php

namespace Martis\Fields;

/**
 * DateTime field — includes both date and time.
 *
 * Renders as datetime-local input in the React frontend.
 * Extends Date for shared date logic.
 */
class DateTime extends Date
{
    /**
     * Type.
     */
    public function type(): string
    {
        return 'datetime';
    }
}
