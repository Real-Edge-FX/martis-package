<?php

declare(strict_types=1);

namespace Martis\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use ReflectionProperty;
use SplObjectStorage;

/**
 * Dev-mode guard against per-row relationship loads while a listing is
 * serialized (`martis.dev.prevent_lazy_loading_in_listings`, off by default).
 *
 * It never turns on Laravel's global `Model::preventLazyLoading()`. It marks
 * only the rows of the page (and the relations already loaded on them) as
 * lazy-loading-averse for the length of the serialization, then puts every
 * flag back. In `local` and `testing` a lazy load then throws Laravel's
 * `LazyLoadingViolationException`; elsewhere it is logged as a warning and
 * the load goes ahead. An application that registered its own
 * `Model::handleLazyLoadingViolationUsing()` handler keeps it in the
 * environments that throw; elsewhere a temporary logging handler replaces it
 * for the length of the serialization, and the application's is restored.
 */
final class ListingGuard
{
    public static function enabled(): bool
    {
        return (bool) config('martis.dev.prevent_lazy_loading_in_listings', false);
    }

    /**
     * Run `$serialize`, guarded when the config key is on.
     *
     * @template TReturn
     *
     * @param  iterable<mixed>  $rows  The models of the page.
     * @param  Closure(): TReturn  $serialize
     * @return TReturn
     */
    public static function run(iterable $rows, Closure $serialize): mixed
    {
        if (! self::enabled()) {
            return $serialize();
        }

        /** @var SplObjectStorage<Model, bool> $previous */
        $previous = new SplObjectStorage;
        self::flag($rows, $previous);

        $swapped = false;
        $handler = null;

        if (! app()->environment(['local', 'testing'])) {
            $handler = (new ReflectionProperty(Model::class, 'lazyLoadingViolationCallback'))->getValue();
            Model::handleLazyLoadingViolationUsing(static function (Model $model, string $relation): void {
                Log::warning(sprintf(
                    'Lazy loading of [%s] on [%s] while serializing a Martis listing: prefetch it in prefetchIndexPage() or eager load it.',
                    $relation,
                    $model::class,
                ));
            });
            $swapped = true;
        }

        try {
            return $serialize();
        } finally {
            if ($swapped) {
                Model::handleLazyLoadingViolationUsing($handler);
            }

            foreach ($previous as $model) {
                $model->preventsLazyLoading = $previous[$model];
            }
        }
    }

    /**
     * @param  iterable<mixed>  $models
     * @param  SplObjectStorage<Model, bool>  $previous
     */
    private static function flag(iterable $models, SplObjectStorage $previous): void
    {
        foreach ($models as $model) {
            if (is_iterable($model)) {
                self::flag($model, $previous);

                continue;
            }

            if (! $model instanceof Model || $previous->contains($model)) {
                continue;
            }

            $previous[$model] = $model->preventsLazyLoading;
            $model->preventsLazyLoading = true;

            self::flag($model->getRelations(), $previous);
        }
    }
}
