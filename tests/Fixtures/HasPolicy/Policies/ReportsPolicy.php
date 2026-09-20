<?php

declare(strict_types=1);

namespace Martis\Tests\Fixtures\HasPolicy\Policies;

/**
 * Convention target for a `ReportsDashboard` / `ReportsTool` fixture when
 * `martis.policy_namespace` points at this namespace: `{BaseName}Policy`
 * with the entity suffix stripped.
 */
class ReportsPolicy
{
    public function view($user): bool
    {
        return true;
    }
}
