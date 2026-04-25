<?php

namespace Martis\Http\Controllers;

use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Controller;
use Martis\Http\Resources\JsonErrorResponse;

/**
 * REST endpoints powering the in-app notifications bell dropdown.
 *
 * Backed by Laravel's standard `notifications` table — any
 * `Notification` class delivered via the `database` channel surfaces
 * here automatically. The expected payload convention (Martis
 * `data` JSON) is documented in
 * `stubs/create_martis_notifications_table.php.stub`.
 *
 * All endpoints scope to the authenticated user and silently no-op when
 * the user is unauthenticated (keeps polling cheap on the login screen).
 */
class NotificationController extends Controller
{
    /**
     * Paginated index. The bell dropdown only consumes the first page;
     * a future "View all" route may use the rest of the pagination
     * surface.
     *
     * Query params:
     *  - `per_page` (int, default 10, capped at 50)
     *  - `unread_only` (bool, default false)
     */
    public function index(Request $request): IlluminateJsonResponse
    {
        if (! $this->featureEnabled()) {
            return $this->disabled();
        }

        $user = $request->user();
        if ($user === null) {
            return new IlluminateJsonResponse([
                'data' => [],
                'meta' => ['total' => 0, 'unread' => 0],
            ]);
        }

        $perPage = (int) $request->query('per_page', (string) (config('martis.notifications.max_in_dropdown', 10)));
        $perPage = max(1, min(50, $perPage));
        $unreadOnly = filter_var($request->query('unread_only', false), FILTER_VALIDATE_BOOLEAN);

        $query = $user->notifications()->latest();
        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        $paginated = $query->paginate($perPage);
        $unread = (int) $user->unreadNotifications()->count();

        return new IlluminateJsonResponse([
            'data' => $paginated->getCollection()->map(fn (DatabaseNotification $n) => $this->serialize($n))->all(),
            'meta' => [
                'total' => (int) $paginated->total(),
                'per_page' => (int) $paginated->perPage(),
                'current_page' => (int) $paginated->currentPage(),
                'last_page' => (int) $paginated->lastPage(),
                'unread' => $unread,
            ],
        ]);
    }

    /**
     * Lightweight unread-count endpoint — the bell badge polls this
     * (default every 60s). Cheap because it's a single COUNT query on
     * an indexed `notifiable` morph.
     */
    public function unreadCount(Request $request): IlluminateJsonResponse
    {
        if (! $this->featureEnabled()) {
            return $this->disabled();
        }

        $user = $request->user();
        if ($user === null) {
            return new IlluminateJsonResponse(['unread' => 0]);
        }

        return new IlluminateJsonResponse([
            'unread' => (int) $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark a single notification as read. The dropdown calls this on
     * every notification click before navigating to its action URL.
     */
    public function markRead(Request $request, string $id): IlluminateJsonResponse
    {
        if (! $this->featureEnabled()) {
            return $this->disabled();
        }

        $user = $request->user();
        if ($user === null) {
            return JsonErrorResponse::forbidden(__('martis::messages.unauthorized'))->toResponse();
        }

        $notification = $user->notifications()->where('id', $id)->first();
        if (! $notification instanceof DatabaseNotification) {
            return JsonErrorResponse::notFound()->toResponse();
        }

        $notification->markAsRead();

        return new IlluminateJsonResponse(['data' => $this->serialize($notification->fresh())]);
    }

    /**
     * Mark every unread notification as read for the current user.
     */
    public function markAllRead(Request $request): IlluminateJsonResponse
    {
        if (! $this->featureEnabled()) {
            return $this->disabled();
        }

        $user = $request->user();
        if ($user === null) {
            return JsonErrorResponse::forbidden(__('martis::messages.unauthorized'))->toResponse();
        }

        $user->unreadNotifications->markAsRead();

        return new IlluminateJsonResponse([
            'data' => null,
            'meta' => ['unread' => 0],
        ]);
    }

    /**
     * Permanently delete a single notification.
     */
    public function destroy(Request $request, string $id): IlluminateJsonResponse
    {
        if (! $this->featureEnabled()) {
            return $this->disabled();
        }

        $user = $request->user();
        if ($user === null) {
            return JsonErrorResponse::forbidden(__('martis::messages.unauthorized'))->toResponse();
        }

        $notification = $user->notifications()->where('id', $id)->first();
        if (! $notification instanceof DatabaseNotification) {
            return JsonErrorResponse::notFound()->toResponse();
        }

        $notification->delete();

        return new IlluminateJsonResponse(['data' => null]);
    }

    /**
     * Permanently delete every notification for the current user.
     */
    public function clearAll(Request $request): IlluminateJsonResponse
    {
        if (! $this->featureEnabled()) {
            return $this->disabled();
        }

        $user = $request->user();
        if ($user === null) {
            return JsonErrorResponse::forbidden(__('martis::messages.unauthorized'))->toResponse();
        }

        $user->notifications()->delete();

        return new IlluminateJsonResponse(['data' => null]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function featureEnabled(): bool
    {
        return (bool) config('martis.notifications.enabled', true);
    }

    protected function disabled(): IlluminateJsonResponse
    {
        return new IlluminateJsonResponse([
            'data' => [],
            'meta' => ['enabled' => false, 'unread' => 0],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(DatabaseNotification $notification): array
    {
        $data = $notification->data ?? [];
        $level = is_array($data) && isset($data['level']) && is_string($data['level']) ? $data['level'] : 'info';

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => is_array($data) ? ($data['title'] ?? class_basename($notification->type)) : class_basename($notification->type),
            'message' => is_array($data) ? ($data['message'] ?? null) : null,
            'level' => $level,
            'icon' => is_array($data) ? ($data['icon'] ?? null) : null,
            'action_url' => is_array($data) ? ($data['action_url'] ?? null) : null,
            'action_label' => is_array($data) ? ($data['action_label'] ?? null) : null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
