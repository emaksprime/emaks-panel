<?php

namespace App\Http\Controllers;

use App\Services\PanelNavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __invoke(Request $request, PanelNavigationService $navigation): RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        return redirect()->to($navigation->homePathFor($request->user()));
    }
}
