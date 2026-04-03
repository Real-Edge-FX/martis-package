<?php

namespace Martis\Http\Controllers;

use Illuminate\Http\Response;

class DashboardController extends MartisController
{
    public function index(): Response
    {
        return response(view('martis::app'));
    }
}
