<?php

namespace Martis\Dashboards;

/**
 * The built-in Martis landing dashboard.
 *
 * Renders the navigation-derived summary view (stat cards + resource quick-access
 * cards) that Martis ships out of the box. Register it from an app's service
 * provider to expose this default layout alongside custom dashboards:
 *
 *   Martis::dashboards([
 *       \Martis\Dashboards\DefaultDashboard::class,
 *       MyCustomDashboard::class,
 *   ]);
 *
 * The frontend detects the 'default' layout flag on the dashboard payload and
 * renders the built-in view instead of a cards grid.
 */
class DefaultDashboard extends Dashboard
{
    public function __construct(?string $name = null, ?string $uriKey = null)
    {
        parent::__construct($name ?? __('martis::resources.default_dashboard'), $uriKey ?? 'default');
    }

    public function layoutType(): string
    {
        return 'default';
    }

    public function showRefreshButton(): bool
    {
        return false;
    }

    /** {@inheritdoc} */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'layout' => $this->layoutType(),
        ]);
    }
}
