<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MartisController extends Controller
{
    public function index(Request $request): View
    {
        return view('martis::app', [
            'config' => [
                'path' => config('martis.path', 'martis'),
            ],
        ]);
    }
}
