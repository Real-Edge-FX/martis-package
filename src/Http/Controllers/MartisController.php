<?php

namespace Martis\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MartisController extends Controller
{
    public function index(Request $request)
    {
        return view('martis::app', [
            'config' => [
                'path' => config('martis.path', 'martis'),
            ],
        ]);
    }
}
