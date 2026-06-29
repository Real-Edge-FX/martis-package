<?php

namespace Martis\Fields;

/**
 * DateTime field — includes both date and time.
 *
 * Renders as datetime-local input in the React frontend.
 * Extends Date for shared date logic, but is born in datetime mode:
 * Date defaults to date-only (withTime=false, 'Y-m-d'), so without these
 * overrides resolve()/store would strip the time portion from the value.
 */
class DateTime extends Date
{
    protected bool $withTime = true;

    protected string $displayFormat = 'Y-m-d H:i:s';

    protected string $storeFormat = 'Y-m-d H:i:s';

    /**
     * Type.
     */
    public function type(): string
    {
        return 'datetime';
    }
}
