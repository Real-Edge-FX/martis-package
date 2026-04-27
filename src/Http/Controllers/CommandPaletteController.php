<?php

namespace Martis\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Martis\Contracts\ActionContract;
use Martis\Models\ActionEvent;
use Martis\Resource;
use Martis\ResourceRegistry;
use Throwable;

/**
 * Backend for the global command palette (⌘K / "/").
 *
 * Aggregates three live sections in a single response so the overlay
 * avoids the chattiness of hitting `/api/navigation`, `/api/resources/*`
 * and `/api/action-events` separately on every keystroke:
 *
 *  - **Resources** — every registered resource the current user is
 *    authorised to view. Same filter the sidebar applies.
 *  - **Actions** — every standalone action (`Action::standalone()`) across
 *    every resource, tagged with the owning resource so the frontend can
 *    render "Run <action> on <resource>".
 *  - **Recent** — the authenticated user's latest 8 `martis_action_events`
 *    rows, linking back to the affected record when `model_id` is set.
 *
 * All three arrays come out ordered for deterministic rendering — any
 * fuzzy match / scoring happens client-side.
 */
class CommandPaletteController extends MartisController
{
    public function __construct(private readonly ResourceRegistry $registry) {}

    /**
     * @response array{
     *   resources: list<array{key: string, uriKey: string, label: string, icon: string|null, group: string|null, url: string}>,
     *   actions: list<array{key: string, label: string, icon: string|null, destructive: bool, resource: string, resourceUriKey: string, url: string}>,
     *   recent: list<array{key: int, label: string, subtitle: string|null, url: string|null, created_at: string}>,
     * }
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'resources' => $this->resources($request),
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

            if (! $class::displayInNavigation()) {
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
                'group' => $instance->group(),
                'url' => '/resources/'.$uriKey,
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
                    'icon' => $action->icon,
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
                ->limit(8)
                ->get(['id', 'name', 'model_type', 'model_id', 'target_type', 'status', 'created_at']);
        } catch (Throwable) {
            // Table missing (consumer skipped the audit migration) — silently drop.
            return [];
        }

        return $rows->map(function (ActionEvent $e) {
            $uriKey = $this->uriKeyForModelType((string) $e->model_type);

            return [
                'key' => (int) $e->id,
                'label' => (string) $e->name,
                'subtitle' => $e->model_id !== null && $uriKey !== null
                    ? $uriKey.' #'.$e->model_id
                    : ((string) $e->status),
                'url' => $uriKey !== null && $e->model_id !== null
                    ? '/resources/'.$uriKey.'/'.$e->model_id
                    : null,
                'created_at' => $e->created_at?->toIso8601String() ?? '',
            ];
        })->values()->all();
    }

    /** Resolve the resource uriKey for an Eloquent class name, or null if
     *  the model isn't registered with Martis (resource deleted, polymorphic
     *  target, etc.). */
    private function uriKeyForModelType(string $modelType): ?string
    {
        if ($modelType === '') {
            return null;
        }

        foreach ($this->registry->list() as $class) {
            if ($class::model() === $modelType) {
                return $class::uriKey();
            }
        }

        return null;
    }
}
