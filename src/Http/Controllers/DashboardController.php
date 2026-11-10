<?php

namespace Martis\Http\Controllers;

use Illuminate\Http\Response;
use Martis\Dashboards\DashboardList;
use Throwable;

class DashboardController extends MartisController
{
    /**
     * Render the admin panel (React SPA entry point).
     *
     * The page carries the dashboards this user may see in its boot payload
     * (`window.MartisConfig.dashboards`, the same list `GET /api/dashboards`
     * answers), so the breadcrumb, the sidebar's DASHBOARDS group, the tab
     * strip and the tab title are right on the first render (v2.19.0).
     */
    public function index(): Response
    {
        return response(view('martis::app', ['bootDashboardsJson' => $this->bootDashboardsJson()]));
    }

    /**
     * The list as a JavaScript literal, or null to leave it out.
     */
    private function bootDashboardsJson(): ?string
    {
        // The shell serves every panel page, not only dashboards: a
        // dashboard that throws while resolving, or whose meta() cannot be
        // encoded (a NAN), must not take the whole panel down with it. The
        // SPA then requests the list itself and shows that request's error,
        // as before v2.19.0. Encoded here, inside the try: in the view a
        // failed json_encode() would print nothing and break the script.
        try {
            $list = app(DashboardList::class)->forShell(request());

            return $list === null ? null : json_encode(
                $list,
                JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
