<?php

declare(strict_types=1);

namespace Martis\Impersonation;

use Carbon\Carbon;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Event;
use Martis\Contracts\NotImpersonable;
use Martis\Impersonation\Events\ImpersonationStarted;
use Martis\Impersonation\Events\ImpersonationStopped;
use RuntimeException;

/**
 * Impersonation service.
 *
 * Lets a privileged operator log in as another user for the duration
 * of a session. The original user's id is stashed in the session so
 * a single round-trip restores it. Designed to play nice with the
 * standard Laravel session guard — no extra authentication scheme.
 *
 * The feature is **opt-in**. Consumers gate it through:
 *
 *   1. `martis.impersonation.enabled` config flag (default `false`).
 *   2. The `martis-impersonate` Gate (define it yourself; the package
 *      does not pre-register one to avoid blanket-permitting admins).
 *
 * Both must say "yes" before `start()` succeeds.
 */
class ImpersonationManager
{
    public function __construct(
        private readonly Application $app,
        private readonly AuthManager $auth,
    ) {}

    /**
     * Begin impersonating the target user.
     *
     * The current authenticated user (the operator) is recorded in
     * the session so `stop()` can restore them. The auth guard is
     * then logged in as the target.
     *
     * Throws RuntimeException when the feature is disabled, no user
     * is currently authenticated, the operator and target are the
     * same person, or impersonation is already active (chaining is
     * not supported on purpose — it would be a foot-gun).
     */
    public function start(Authenticatable $target): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Impersonation is disabled. Set `martis.impersonation.enabled` to true.');
        }

        $guard = $this->guard();
        $operator = $this->auth->guard($guard)->user();

        if ($operator === null) {
            throw new RuntimeException('Cannot start impersonation without an authenticated operator.');
        }

        if ($this->isActive()) {
            throw new RuntimeException('Impersonation is already active. Stop it before starting a new session.');
        }

        if ($operator->getAuthIdentifier() === $target->getAuthIdentifier()) {
            throw new RuntimeException('Cannot impersonate yourself.');
        }

        // Per-target opt-out (v1.8.8). Models that implement
        // `NotImpersonable` are off-limits — system accounts, API users,
        // super-admins, etc. The check runs before the session is
        // mutated so a denied attempt has no side-effect.
        if ($target instanceof NotImpersonable) {
            throw new RuntimeException('This user cannot be impersonated.');
        }

        $this->session()->put($this->sessionKey(), [
            'original' => $operator->getAuthIdentifier(),
            'target' => $target->getAuthIdentifier(),
            'started_at' => now()->toIso8601String(),
        ]);

        $this->auth->guard($guard)->login($target);

        Event::dispatch(new ImpersonationStarted($operator, $target));
    }

    /**
     * Stop the current impersonation session and restore the operator.
     *
     * No-op when no impersonation is active — calling stop() repeatedly
     * is safe.
     */
    public function stop(): void
    {
        if (! $this->isActive()) {
            return;
        }

        $stashed = $this->session()->get($this->sessionKey());
        $this->session()->forget($this->sessionKey());

        $guard = $this->guard();

        if (! is_array($stashed) || ! isset($stashed['original'])) {
            // Defensive — clear the auth guard anyway so we don't
            // leak the impersonated session.
            $this->auth->guard($guard)->logout();

            return;
        }

        $original = $this->resolveUser($stashed['original']);
        if ($original === null) {
            $this->auth->guard($guard)->logout();

            return;
        }

        $previousTarget = $this->auth->guard($guard)->user();

        $this->auth->guard($guard)->login($original);

        if ($previousTarget !== null) {
            Event::dispatch(new ImpersonationStopped($original, $previousTarget));
        }
    }

    /**
     * True when the current impersonation session has exceeded the
     * configured `max_duration_minutes`. Used by the request-level
     * middleware to auto-stop expired sessions.
     */
    public function isExpired(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $max = (int) config('martis.impersonation.max_duration_minutes', 0);
        if ($max <= 0) {
            return false;
        }

        $stashed = $this->session()->get($this->sessionKey());
        $startedAt = is_array($stashed) ? ($stashed['started_at'] ?? null) : null;
        if (! is_string($startedAt) || $startedAt === '') {
            return false;
        }

        try {
            $started = Carbon::parse($startedAt);
        } catch (\Throwable) {
            return false;
        }

        return $started->addMinutes($max)->isPast();
    }

    /**
     * True when the current request is running inside an active
     * impersonation session.
     */
    public function isActive(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return $this->session()->has($this->sessionKey());
    }

    /**
     * Return the operator (the user who started the impersonation),
     * or null when no impersonation is active.
     */
    public function originalUser(): ?Authenticatable
    {
        if (! $this->isActive()) {
            return null;
        }

        $stashed = $this->session()->get($this->sessionKey());

        if (! is_array($stashed) || ! isset($stashed['original'])) {
            return null;
        }

        return $this->resolveUser($stashed['original']);
    }

    /**
     * Return the user currently being impersonated — i.e. the user
     * the auth guard reports as logged in. Returns null when no
     * impersonation is active.
     */
    public function currentTarget(): ?Authenticatable
    {
        if (! $this->isActive()) {
            return null;
        }

        return $this->auth->guard($this->guard())->user();
    }

    /**
     * Master switch. Reads `martis.impersonation.enabled` (default `false`).
     */
    public function enabled(): bool
    {
        return (bool) config('martis.impersonation.enabled', false);
    }

    /**
     * The auth guard impersonation operates on.
     */
    public function guard(): string
    {
        return (string) config('martis.impersonation.guard', 'web');
    }

    /**
     * Snapshot of the impersonation state for the banner endpoint.
     *
     * @return array{
     *     active: bool,
     *     enabled: bool,
     *     original: array{id: int|string|null, label: string|null}|null,
     *     target: array{id: int|string|null, label: string|null}|null,
     *     started_at: string|null,
     * }
     */
    public function snapshot(): array
    {
        if (! $this->isActive()) {
            return [
                'active' => false,
                'enabled' => $this->enabled(),
                'original' => null,
                'target' => null,
                'started_at' => null,
            ];
        }

        $stashed = $this->session()->get($this->sessionKey());
        $startedAt = is_array($stashed) ? ($stashed['started_at'] ?? null) : null;

        return [
            'active' => true,
            'enabled' => $this->enabled(),
            'original' => $this->describe($this->originalUser()),
            'target' => $this->describe($this->currentTarget()),
            'started_at' => is_string($startedAt) ? $startedAt : null,
        ];
    }

    /**
     * Reload a user from the configured guard's user provider.
     */
    private function resolveUser(int|string $id): ?Authenticatable
    {
        $provider = $this->auth->createUserProvider(
            (string) config("auth.guards.{$this->guard()}.provider"),
        );

        return $provider?->retrieveById($id);
    }

    private function session(): Session
    {
        /** @var Session $session */
        $session = $this->app->make('session.store');

        return $session;
    }

    private function sessionKey(): string
    {
        return (string) config(
            'martis.impersonation.session_key',
            'martis.impersonation',
        );
    }

    /**
     * Build the describe payload for the snapshot endpoint. Uses a
     * `name` attribute when present, otherwise the email, otherwise
     * the auth identifier.
     *
     * @return array{id: int|string|null, label: string|null}|null
     */
    private function describe(?Authenticatable $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $label = null;
        foreach (['name', 'email'] as $attribute) {
            $value = $user->{$attribute} ?? null;
            if (is_string($value) && $value !== '') {
                $label = $value;
                break;
            }
        }

        return [
            'id' => $user->getAuthIdentifier(),
            'label' => $label,
        ];
    }
}
