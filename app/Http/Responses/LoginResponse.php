<?php

namespace App\Http\Responses;

use App\Services\PanelNavigationService;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function __construct(
        private readonly PanelNavigationService $navigation,
    ) {
    }

    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->noContent();
        }

        return redirect()->intended($this->navigation->homePathFor($request->user()));
    }
}
