<?php

declare(strict_types=1);

namespace Martis\Console\Concerns;

/**
 * Whether a console command may ask a question.
 *
 * Symfony's `isInteractive()` is true unless `--no-interaction` is passed,
 * but a piped stdin (`yes | php artisan …`, `docker compose exec -T`, a CI
 * step) strips the TTY without that flag: a question there reads the pipe
 * (`y` became a column name) or waits forever. A command using this asks
 * only when the input is interactive AND stdin is a real TTY, and never
 * inside the unit tests; otherwise it takes its default.
 */
trait AsksOnlyOnATerminal
{
    protected function canPrompt(): bool
    {
        return $this->input->isInteractive()
            && ! $this->insideUnitTests()
            && $this->stdinIsTty();
    }

    protected function insideUnitTests(): bool
    {
        return app()->runningUnitTests();
    }

    /**
     * Whether stdin is a real TTY: `stream_isatty()`, else `posix_isatty()`
     * on runtimes without it, else false (no way to tell, so no prompt).
     */
    protected function stdinIsTty(): bool
    {
        if (! defined('STDIN')) {
            return false;
        }

        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDIN);
        }

        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDIN);
        }

        return false;
    }
}
