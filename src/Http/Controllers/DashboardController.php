<?php

namespace Martis\Http\Controllers;

use Illuminate\Http\Response;

class DashboardController extends MartisController
{
    /** Render the admin dashboard (React SPA entry point). */
    public function index(): Response
    {
        return response(view('martis::app'));
    }
}
