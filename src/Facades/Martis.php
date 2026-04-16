<?php

namespace Martis\Facades;

use Illuminate\Support\Facades\Facade;
use Martis\MartisManager;

/**
 * @method static MartisManager mainMenu(\Closure $resolver)
 * @method static MartisManager forgetMainMenu()
 * @method static MartisManager dashboards(array $dashboards)
 * @method static list<\Martis\Contracts\DashboardContract> resolveDashboards(\Illuminate\Http\Request $request)
 *
 * @see MartisManager
 */
class Martis extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MartisManager::class;
    }
}
