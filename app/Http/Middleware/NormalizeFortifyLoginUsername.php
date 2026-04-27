<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeFortifyLoginUsername
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('post') && $request->is('login') && ! $request->has('username') && $request->has('email')) {
            $request->merge(['username' => $request->input('email')]);
        }

        return $next($request);
    }
}
