<?php

namespace App\Http\Controllers;

use App\Services\PanelNavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request, PanelNavigationService $navigation): RedirectResponse|Response
    {
        if (! $request->user()) {
            return Inertia::render('auth/login', [
                'canResetPassword' => false,
                'canRegister' => false,
                'status' => $request->session()->get('status'),
            ]);
        }

        return redirect()->to($navigation->homePathFor($request->user()));
    }
}
