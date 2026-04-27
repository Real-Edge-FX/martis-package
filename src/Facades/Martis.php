<?php

namespace Martis\Facades;

use Illuminate\Support\Facades\Facade;
use Martis\MartisManager;

/**
 * @method static MartisManager mainMenu(\Closure $resolver)
 * @method static MartisManager forgetMainMenu()
 * @method static MartisManager dashboards(array $dashboards)
 * @method static list<\Martis\Contracts\DashboardContract> resolveDashboards(\Illuminate\Http\Request $request)
 * @method static MartisManager tools(array $tools)
 * @method static list<\Martis\Contracts\ToolContract> resolveTools(\Illuminate\Http\Request $request)
 * @method static \Martis\Contracts\ToolContract|null findTool(\Illuminate\Http\Request $request, string $uriKey)
 * @method static MartisManager pageTitleUsing(\Closure $resolver)
 * @method static MartisManager forgetPageTitle()
 * @method static string resolvePageTitle(\Illuminate\Http\Request $request)
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
