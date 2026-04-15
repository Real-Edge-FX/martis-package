<?php

namespace Martis\Facades;

use Illuminate\Support\Facades\Facade;
use Martis\MartisManager;

/**
 * @method static MartisManager mainMenu(\Closure $resolver)
 * @method static MartisManager forgetMainMenu()
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
