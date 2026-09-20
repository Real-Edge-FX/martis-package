<?php

declare(strict_types=1);

namespace Martis\Tests\Fixtures\DiscoveryNamespace;

use Illuminate\Database\Eloquent\Model;

/**
 * Model backing {@see Resources\DiscoveredWidgetResource}. Lives outside
 * the scanned `Resources/` and `Tools/` folders on purpose.
 */
class DiscoveredWidget extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}
