<?php

declare(strict_types=1);

namespace Martis\Tools;

use Illuminate\Support\ServiceProvider;
use Martis\Contracts\ToolContract;
use Martis\Facades\Martis;

/**
 * Base ServiceProvider for Tools distributed as Composer packages.
 *
 * Lets a tool author ship a self-contained package that:
 *
 *   1. Registers itself with Martis without the consumer touching
 *      their `MartisServiceProvider`.
 *   2. Loads its own routes, views, translations, migrations.
 *   3. Publishes config / assets / migrations under named tags so
 *      `php artisan vendor:publish --tag=...` works out of the box.
 *
 * Subclass it in your package's service provider:
 *
 *     class MyToolServiceProvider extends ToolServiceProvider
 *     {
 *         protected function tools(): array
 *         {
 *             return [
 *                 new MyTool(),
 *             ];
 *         }
 *
 *         public function boot(): void
 *         {
 *             parent::boot();
 *             $this->loadViewsFrom(__DIR__.'/../resources/views', 'my-tool');
 *             $this->publishes([
 *                 __DIR__.'/../config/my-tool.php' => config_path('my-tool.php'),
 *             ], 'my-tool-config');
 *         }
 *     }
 *
 * Then ship the provider in your package's `composer.json`:
 *
 *     "extra": {
 *         "laravel": {
 *             "providers": ["YourVendor\\YourTool\\MyToolServiceProvider"]
 *         }
 *     }
 *
 * Laravel auto-discovers the provider on `composer require`. The
 * consumer never touches their own service provider.
 */
abstract class ToolServiceProvider extends ServiceProvider
{
    /**
     * Tools to register with Martis. Override in your subclass.
     *
     * Returns either Tool instances or class-strings — class-strings
     * are instantiated lazily per request, instances are kept verbatim.
     *
     * @return list<class-string<ToolContract>|ToolContract>
     */
    protected function tools(): array
    {
        return [];
    }

    /**
     * Default `register()` is a no-op. Override only if your package
     * needs to bind container singletons. Most tool packages don't.
     */
    public function register(): void
    {
        //
    }

    /**
     * Default `boot()` registers your tools with Martis. Subclasses
     * SHOULD call `parent::boot()` before doing their own work so the
     * tools are visible to the menu / API by the time later boot
     * hooks run.
     *
     * The actual per-tool `boot()` lifecycle (route registration,
     * publishing, etc.) is invoked by the package's own
     * `MartisManager::bootTools()` once the application has finished
     * registering, so subclasses do NOT need to call it themselves.
     */
    public function boot(): void
    {
        $tools = $this->tools();

        if ($tools === []) {
            return;
        }

        // Merge with any tools the consumer might have already
        // registered — class-strings + instances both work, and the
        // existing list wins ordering since later registrations
        // typically come from leaf packages.
        $manager = Martis::getFacadeRoot();
        if ($manager === null) {
            return;
        }

        $existing = property_exists($manager, 'tools') ? $manager->tools ?? [] : [];

        // Reflection-safe append even when `tools` is protected.
        $reflection = new \ReflectionProperty($manager, 'tools');
        $reflection->setAccessible(true);
        $current = (array) $reflection->getValue($manager);
        $reflection->setValue($manager, array_values(array_merge($current, $tools)));
    }
}
