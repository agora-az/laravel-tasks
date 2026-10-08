<?php

namespace App\Http\Middleware;

use App\Support\ApplicationAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApplicationAdmin
{
    public function __construct(private readonly ApplicationAdmin $applicationAdmin)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->applicationAdmin->allows($request->user()), 403);

        return $next($request);
    }
}