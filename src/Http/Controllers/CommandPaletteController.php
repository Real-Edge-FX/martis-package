<?php

namespace Martis\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Martis\Contracts\ActionContract;
use Martis\MartisManager;
use Martis\Models\ActionEvent;
use Martis\Resource;
use Martis\ResourceRegistry;
use Throwable;

/**
 * Backend for the global command palette (⌘K / "/").
 *
 * Aggregates four live sections in a single response so the overlay
 * avoids the chattiness of hitting `/api/navigation`, `/api/resources/*`
 * and `/api/action-events` separately on every keystroke:
 *
 *  - **Resources** — every registered resource the current user is
 *    authorised to view. Same filter the sidebar applies.
 *  - **Tools** — every registered custom Tool the current user is
 *    authorised to see (same `authorizedToSee` gate as the sidebar), so
 *    ⌘K can jump to a Tool by name just like a resource.
 *  - **Actions** — every standalone action (`Action::standalone()`) across
 *    every resource, tagged with the owning resource so the frontend can
 *    render "Run <action> on <resource>".
 *  - **Recent** — the authenticated user's latest 5 `martis_action_events`
 *    rows, linking back to the affected record when `model_id` is set.
 *
 * All arrays come out ordered for deterministic rendering — any
 * fuzzy match / scoring happens client-side.
 */
class CommandPaletteController extends MartisController
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly MartisManager $martis,
    ) {}

    /**
     * @response array{
     *   resources: list<array{key: string, uriKey: string, label: string, icon: string|null, group: string|null, url: string}>,
     *   tools: list<array{key: string, uriKey: string, label: string, icon: string|null, group: string|null, url: string}>,
     *   actions: list<array{key: string, label: string, icon: string|null, destructive: bool, resource: string, resourceUriKey: string, url: string}>,
     *   recent: list<array{key: int, label: string, subtitle: string|null, url: string|null, created_at: string}>,
     * }
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'resources' => $this->resources($request),
            'tools' => $this->tools($request),
            'actions' => $this->actions($request),
            'recent' => $this->recent($request),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function resources(Request $request): array
    {
        $out = [];
        foreach ($this->registry->list() as $class) {
            /** @var resource $instance */
            $instance = new $class;

            if (! $class::displayInNavigation() || ! $class::routable()) {
                continue;
            }

            if (! $instance->authorizedToViewAny($request)) {
                continue;
            }

            $uriKey = $class::uriKey();
            $out[] = [
                'key' => 'resource:'.$uriKey,
                'uriKey' => $uriKey,
                'label' => $class::label(),
                'icon' => $instance->icon(),
                // A System-section resource (belongsToSystemSection() === true)
                // is deliberately group() === null — the sidebar buckets it
                // under the "System" header via appendSystemSection(). Mirror
                // that here so the palette tag agrees with the sidebar instead
                // of rendering no tag.
                'group' => $instance->belongsToSystemSection()
                    ? __('martis::messages.system')
                    : $instance->group(),
                'url' => '/resources/'.$uriKey,
            ];
        }

        usort($out, fn ($a, $b) => strcasecmp((string) $a['label'], (string) $b['label']));

        return $out;
    }

    /**
     * Every registered custom Tool the user is authorised to see. Uses the
     * same `authorizedToSee` gate as the sidebar (via
     * `MartisManager::resolveTools()`), so a Tool never surfaces in ⌘K for a
     * user who cannot open it. Shaped like a resource row so the frontend
     * renders it in an identical "jump to" section.
     *
     * @return list<array<string, mixed>>
     */
    private function tools(Request $request): array
    {
        $out = [];
        foreach ($this->martis->resolveTools($request) as $tool) {
            $uriKey = $tool->uriKey();
            $out[] = [
                'key' => 'tool:'.$uriKey,
                'uriKey' => $uriKey,
                'label' => $tool->name(),
                'icon' => $tool->icon(),
                'group' => $tool->menuSection(),
                'url' => '/tools/'.$uriKey,
            ];
        }

        usort($out, fn ($a, $b) => strcasecmp((string) $a['label'], (string) $b['label']));

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function actions(Request $request): array
    {
        $out = [];
        foreach ($this->registry->list() as $class) {
            if (! $class::routable()) {
                continue;
            }

            /** @var resource $instance */
            $instance = new $class;

            if (! $instance->authorizedToViewAny($request)) {
                continue;
            }

            try {
                $actions = $instance->actions($request);
            } catch (Throwable) {
                // A resource whose actions() depends on DB state we cannot
                // touch from a palette fetch shouldn't kill the whole list.
                continue;
            }

            foreach ($actions as $action) {
                if (! $action instanceof ActionContract) {
                    continue;
                }
                if (! $action->isStandalone()) {
                    continue;
                }

                $uriKey = $class::uriKey();
                $out[] = [
                    'key' => 'action:'.$uriKey.':'.$action->uriKey(),
                    'label' => $action->name(),
                    'icon' => $action->getIcon(),
                    'destructive' => $action->isDestructive(),
                    'resource' => $class::label(),
                    'resourceUriKey' => $uriKey,
                    'url' => '/resources/'.$uriKey.'?standalone='.$action->uriKey(),
                ];
            }
        }

        usort(
            $out,
            fn ($a, $b) => strcasecmp((string) $a['resource'], (string) $b['resource'])
                ?: strcasecmp((string) $a['label'], (string) $b['label'])
        );

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function recent(Request $request): array
    {
        $user = $request->user();
        if ($user === null) {
            return [];
        }

        try {
            /** @var Collection<int, ActionEvent> $rows */
            $rows = ActionEvent::query()
                ->where('user_id', $user->getAuthIdentifier())
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'name', 'model_type', 'model_id', 'target_type', 'status', 'created_at']);
        } catch (Throwable) {
            // Table missing (consumer skipped the audit migration) — silently drop.
            return [];
        }

        return $rows->map(function (ActionEvent $e) {
            $uriKey = $this->resolveUriKeyForRecord((string) $e->model_type, $e->model_id);

            return [
                'key' => (int) $e->id,
                'label' => (string) $e->name,
                'subtitle' => $e->model_id !== null && $uriKey !== null
                    ? $uriKey.' #'.$e->model_id
                    : ((string) $e->status),
                'url' => $uriKey !== null && $e->model_id !== null && $this->registry->has($uriKey)
                    ? $this->registry->get($uriKey)::recordHref($e->model_id)
                    : null,
                'created_at' => $e->created_at?->toIso8601String() ?? '',
            ];
        })->values()->all();
    }

    /**
     * Resolve the resource uriKey for an event's subject, or null when the
     * model isn't registered with Martis (resource deleted, polymorphic
     * target, etc.).
     *
     * When SEVERAL registered resources share the same Eloquent model (e.g. an
     * Approval-Queue resource scoped to `pending` and a Processed resource
     * scoped to `approved`/`indexed`, both on `Candidate`), a bare first-match
     * would always pick the first-registered resource — so a processed record's
     * Recent deep-link would open the wrong surface. Disambiguate by loading
     * the record and asking each candidate resource whether it owns it
     * (`matchesRecord()`), in registration order. Falls back to the
     * first-registered resource when the record is gone or none claims it.
     *
     * @param  mixed  $modelId
     */
    private function resolveUriKeyForRecord(string $modelType, $modelId): ?string
    {
        if ($modelType === '') {
            return null;
        }

        /** @var list<class-string<resource>> $matches */
        $matches = [];
        foreach ($this->registry->list() as $class) {
            if ($class::model() === $modelType) {
                $matches[] = $class;
            }
        }

        if ($matches === []) {
            return null;
        }

        // Fast path: exactly one resource owns this model — no record load.
        if (count($matches) === 1) {
            return $matches[0]::uriKey();
        }

        if ($modelId !== null && is_a($modelType, Model::class, true)) {
            $query = $modelType::query();
            // Include soft-deleted rows: a `candidate.deleted`-style record whose
            // resource shows trashed items must still resolve to the right
            // surface (the default scope would hide it and force a first-match).
            if (in_array(SoftDeletes::class, class_uses_recursive($modelType), true)) {
                /** @phpstan-ignore method.notFound */
                $query = $query->withTrashed();
            }

            /** @var Model|null $model */
            $model = $query->whereKey($modelId)->first();
            if ($model !== null) {
                foreach ($matches as $class) {
                    if ((new $class)->matchesRecord($model)) {
                        return $class::uriKey();
                    }
                }
            }
        }

        return $matches[0]::uriKey();
    }
}
