<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;

class DashboardController
{
    public function index(Request $request): string
    {
        return View::render('dashboard/index', [
            'usuario' => Auth::usuario(),
        ]);
    }
}
