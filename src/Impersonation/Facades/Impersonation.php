<?php

declare(strict_types=1);

namespace Martis\Impersonation\Facades;

use Illuminate\Support\Facades\Facade;
use Martis\Impersonation\ImpersonationManager;

/**
 * @method static void start(\Illuminate\Contracts\Auth\Authenticatable $target)
 * @method static void stop()
 * @method static bool isActive()
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null originalUser()
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null currentTarget()
 * @method static bool enabled()
 * @method static string guard()
 * @method static array snapshot()
 *
 * @see ImpersonationManager
 */
class Impersonation extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ImpersonationManager::class;
    }
}
