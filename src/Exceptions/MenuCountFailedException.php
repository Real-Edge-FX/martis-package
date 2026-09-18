<?php

namespace Martis\Exceptions;

use Throwable;

/**
 * Wraps a `menuCount()` failure so it can be reported without breaking
 * the navigation payload or the badges endpoint.
 *
 * Count badges are resilient by design: when a single resource's or
 * tool's `menuCount()` throws, that badge is skipped and the rest of
 * the sidebar renders. Before this exception existed the failure was
 * swallowed with no trace at all, so a missing badge looked like a
 * styling glitch when it was really a server-side exception on the
 * same query that powers the index page.
 *
 * The wrapper is what goes through `report()`, which gives consumers a
 * single class to target in their exception handler (`dontReport`,
 * `level()`, custom `reportable()` callbacks) while the original
 * exception is preserved as `getPrevious()` so the stack trace still
 * points at the real cause.
 */
class MenuCountFailedException extends MartisException
{
    /**
     * @param  string  $subjectClass  The Resource or Tool class whose `menuCount()` threw.
     * @param  string  $badgeKey  The badges-map key of that subject (`resource:{uriKey}` or `tool:{uriKey}`).
     */
    public function __construct(
        private readonly string $subjectClass,
        private readonly string $badgeKey,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf(
                'Martis: menuCount() threw for %s (%s): %s',
                $subjectClass,
                $badgeKey,
                self::describe($previous),
            ),
            'menu_count_failed',
            ['subject' => $subjectClass, 'badge' => $badgeKey],
            500,
            $previous,
        );
    }

    /**
     * The Resource or Tool class whose `menuCount()` threw.
     */
    public function subjectClass(): string
    {
        return $this->subjectClass;
    }

    /**
     * The badges-map key of the failing subject (`resource:{uriKey}` or `tool:{uriKey}`).
     */
    public function badgeKey(): string
    {
        return $this->badgeKey;
    }

    /**
     * Short, single-line description of the underlying failure
     * (`Fully\Qualified\Exception: message`), used for the dev-only
     * `_failed` entry of the badges payload.
     */
    public function describeCause(): string
    {
        return self::describe($this->getPrevious() ?? $this);
    }

    private static function describe(Throwable $e): string
    {
        $message = trim($e->getMessage());

        return $message === '' ? $e::class : $e::class.': '.$message;
    }
}
