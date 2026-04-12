<?php

namespace Martis\Actions\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Models\ActionEvent;

/**
 * Job for executing queued actions.
 *
 * Nova v5 parity: queued actions dispatch as real Laravel jobs
 * with connection/queue customization and per-model status tracking.
 */
class ExecuteAction implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  class-string<Action>  $actionClass
     * @param  array<string, mixed>  $fields
     * @param  list<int|string>  $modelIds
     * @param  class-string  $modelClass
     */
    public function __construct(
        public readonly string $actionClass,
        public readonly array $fields,
        public readonly array $modelIds,
        public readonly string $modelClass,
        public readonly int|string|null $userId = null,
    ) {}

    /**
     * Handle.
     */
    public function handle(): void
    {
        /** @var Action $action */
        $action = new $this->actionClass;

        $fields = new ActionFields($this->fields);

        /** @var Model $modelInstance */
        $modelInstance = new $this->modelClass;

        /** @var Collection<int, Model> $models */
        $models = $modelInstance->newQuery()
            ->whereIn($modelInstance->getKeyName(), $this->modelIds)
            ->get();

        // Capture snapshots before execution
        $snapshots = $models->mapWithKeys(fn (Model $m) => [$m->getKey() => $m->getAttributes()]);

        try {
            $action->handle($fields, $models);

            // Refresh models to capture post-execution state
            $models->each(fn (Model $m) => $m->exists && $m->refresh());

            if ($action->shouldLogEvents()) {
                $this->updateActionEvents($models, $snapshots, 'completed');
            }
        } catch (\Throwable $e) {
            Log::error('Queued action failed', [
                'action' => $this->actionClass,
                'error' => $e->getMessage(),
            ]);

            if ($action->shouldLogEvents()) {
                $this->updateActionEvents($models, $snapshots, 'failed', $e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Update queued action events with final status and original/changes diff.
     *
     * @param  Collection<int, Model>  $models
     * @param  Collection<int|string, array<string, mixed>>  $snapshots
     */
    private function updateActionEvents(Collection $models, Collection $snapshots, string $status, ?string $exception = null): void
    {
        try {
            $actionName = (new $this->actionClass)->name();

            foreach ($models as $model) {
                $key = $model->getKey();
                $originalAttrs = $snapshots->get($key) ?? [];
                $currentAttrs = $model->getAttributes();

                $originalDiff = [];
                $changesDiff = [];

                foreach ($currentAttrs as $attr => $value) {
                    if (array_key_exists($attr, $originalAttrs) && $originalAttrs[$attr] != $value) {
                        $originalDiff[$attr] = $originalAttrs[$attr];
                        $changesDiff[$attr] = $value;
                    }
                }

                ActionEvent::where('name', $actionName)
                    ->where('actionable_type', get_class($model))
                    ->where('actionable_id', $key)
                    ->where('status', 'queued')
                    ->orderByDesc('created_at')
                    ->limit(1)
                    ->update([
                        'status' => $status,
                        'exception' => $exception ?? '',
                        'original' => json_encode($originalDiff),
                        'changes' => json_encode($changesDiff),
                    ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to update action event status', ['error' => $e->getMessage()]);
        }
    }
}
