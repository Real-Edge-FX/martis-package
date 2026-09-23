<?php

namespace Martis\Actions\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\PivotActionEventLog;

/**
 * Job for a queued pivot action (an action implementing `ShouldQueue` run
 * from a BelongsToMany or MorphToMany panel).
 *
 * The related records are reloaded through the parent's relationship, with
 * the pivot columns the relationship field declares, so `handle()` receives
 * each one with its pivot row, as the synchronous run does. The `queued`
 * events written at dispatch are settled with the final status and the
 * pivot diff of the run.
 */
class ExecutePivotAction implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  class-string<Action>  $actionClass
     * @param  array<string, mixed>  $fields
     * @param  class-string<Model>  $parentModelClass
     * @param  string  $relationship  The relationship method, already resolved to a declared field by the controller
     * @param  list<int|string>  $relatedIds
     * @param  list<string>  $pivotColumns
     */
    public function __construct(
        public readonly string $actionClass,
        public readonly array $fields,
        public readonly string $parentModelClass,
        public readonly int|string $parentId,
        public readonly string $relationship,
        public readonly array $relatedIds,
        public readonly array $pivotColumns = [],
        public readonly int|string|null $userId = null,
        public readonly bool $logEvents = true,
    ) {}

    public function handle(): void
    {
        /** @var Action $action */
        $action = new $this->actionClass;

        /** @var Model $parentInstance */
        $parentInstance = new $this->parentModelClass;
        $parent = $parentInstance->newQuery()->whereKey($this->parentId)->first();

        if ($parent === null) {
            Log::warning('Queued pivot action skipped: parent record not found', [
                'action' => $this->actionClass,
                'parent' => $this->parentModelClass,
                'parent_id' => $this->parentId,
            ]);

            return;
        }

        $relation = $parent->{$this->relationship}();
        if (! $relation instanceof BelongsToMany) {
            return;
        }

        if ($this->pivotColumns !== []) {
            $relation->withPivot($this->pivotColumns);
        }

        /** @var Collection<int, Model> $models */
        $models = $relation->whereIn($relation->getRelated()->getQualifiedKeyName(), $this->relatedIds)->get();

        $before = $this->logEvents ? PivotActionEventLog::pivotRows($relation, $models) : [];

        try {
            $action->handle(new ActionFields($this->fields), $models);

            if ($this->logEvents) {
                PivotActionEventLog::settle($action, $parent, $relation, $models, 'completed', null, $before, PivotActionEventLog::pivotRows($relation, $models));
            }
        } catch (\Throwable $e) {
            Log::error('Queued pivot action failed', [
                'action' => $this->actionClass,
                'error' => $e->getMessage(),
            ]);

            if ($this->logEvents) {
                PivotActionEventLog::settle($action, $parent, $relation, $models, 'failed', $e->getMessage(), $before, PivotActionEventLog::pivotRows($relation, $models));
            }

            throw $e;
        }
    }
}
