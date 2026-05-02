<?php

declare(strict_types=1);

namespace Martis\Profile;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads + revokes browser sessions stored by the Laravel `database`
 * session driver. Lives behind a service so the controller stays thin
 * and so consumers can swap or extend the storage shape (e.g. to add
 * geo-IP enrichment) by binding their own implementation.
 *
 * The framework `sessions` table is created by `php artisan
 * session:table` + `migrate`. When the host app uses a different
 * driver (`file`, `cookie`, `array`, `redis-without-table`) the
 * service short-circuits with `supported: false` and the React UI
 * is expected to render a single hint row instead of crashing.
 */
class BrowserSessionsService
{
    /**
     * @return array{sessions: list<array<string, mixed>>, supported: bool, driver: string}
     */
    public function forUser(Authenticatable $user, Request $request): array
    {
        $driver = (string) config('session.driver', 'file');

        if (! $this->driverSupported($driver)) {
            return ['sessions' => [], 'supported' => false, 'driver' => $driver];
        }

        $userId = $this->userId($user);
        if ($userId === null) {
            return ['sessions' => [], 'supported' => true, 'driver' => $driver];
        }

        $rows = DB::table($this->table())
            ->where('user_id', $userId)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->all();

        $currentId = $request->session()->getId();
        $sessions = array_map(function ($row) use ($currentId): array {
            $row = (array) $row;

            return [
                'id' => (string) ($row['id'] ?? ''),
                'ip_address' => (string) ($row['ip_address'] ?? ''),
                'user_agent' => (string) ($row['user_agent'] ?? ''),
                'last_active' => (int) ($row['last_activity'] ?? 0),
                'is_current' => ($row['id'] ?? null) === $currentId,
            ];
        }, $rows);

        return ['sessions' => $sessions, 'supported' => true, 'driver' => $driver];
    }

    /**
     * Revoke every session for the user except the current one. Returns
     * the count of removed rows so the UI can confirm + update its
     * local state without an extra GET.
     *
     * @return array{revoked: int, supported: bool, driver: string}
     */
    public function revokeOthers(Authenticatable $user, Request $request): array
    {
        $driver = (string) config('session.driver', 'file');

        if (! $this->driverSupported($driver)) {
            return ['revoked' => 0, 'supported' => false, 'driver' => $driver];
        }

        $userId = $this->userId($user);
        if ($userId === null) {
            return ['revoked' => 0, 'supported' => true, 'driver' => $driver];
        }

        $currentId = $request->session()->getId();

        $revoked = DB::table($this->table())
            ->where('user_id', $userId)
            ->where('id', '!=', $currentId)
            ->delete();

        return ['revoked' => $revoked, 'supported' => true, 'driver' => $driver];
    }

    /**
     * Revoke a single session row. Targeting the current session is a
     * no-op (`revoked: 0`) so the call cannot accidentally sign the
     * user out of the device they are issuing the request from.
     *
     * @return array{revoked: int, supported: bool, driver: string}
     */
    public function revoke(Authenticatable $user, Request $request, string $sessionId): array
    {
        $driver = (string) config('session.driver', 'file');

        if (! $this->driverSupported($driver)) {
            return ['revoked' => 0, 'supported' => false, 'driver' => $driver];
        }

        $userId = $this->userId($user);
        if ($userId === null) {
            return ['revoked' => 0, 'supported' => true, 'driver' => $driver];
        }

        $currentId = $request->session()->getId();
        if ($sessionId === $currentId) {
            return ['revoked' => 0, 'supported' => true, 'driver' => $driver];
        }

        $revoked = DB::table($this->table())
            ->where('user_id', $userId)
            ->where('id', $sessionId)
            ->delete();

        return ['revoked' => $revoked, 'supported' => true, 'driver' => $driver];
    }

    private function driverSupported(string $driver): bool
    {
        if ($driver !== 'database') {
            return false;
        }

        return Schema::hasTable($this->table());
    }

    private function table(): string
    {
        return (string) config('session.table', 'sessions');
    }

    private function userId(Authenticatable $user): int|string|null
    {
        if ($user instanceof Model) {
            return $user->getKey();
        }

        $id = $user->getAuthIdentifier();

        return is_int($id) || is_string($id) ? $id : null;
    }
}
