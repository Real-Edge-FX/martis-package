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

        try {
            $action->handle($fields, $models);

            if ($action->shouldLogEvents()) {
                $this->updateActionStatus('finished');
            }
        } catch (\Throwable $e) {
            Log::error('Queued action failed', [
                'action' => $this->actionClass,
                'error' => $e->getMessage(),
            ]);

            if ($action->shouldLogEvents()) {
                $this->updateActionStatus('failed', $e->getMessage());
            }

            throw $e;
        }
    }

    private function updateActionStatus(string $status, ?string $exception = null): void
    {
        try {
            ActionEvent::where('name', (new $this->actionClass)->name())
                ->where('status', 'queued')
                ->orderByDesc('created_at')
                ->limit(1)
                ->update([
                    'status' => $status,
                    'exception' => $exception ?? '',
                ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to update action event status', ['error' => $e->getMessage()]);
        }
    }
}
