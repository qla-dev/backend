<?php

namespace App\Http\Middleware;

use App\Services\Accounting\BusinessWorkspace;
use Closure;
use Illuminate\Http\Request;

final class InitializePersonalWorkspace
{
    public function handle(Request $request, Closure $next)
    {
        $id = (int) $request->route('company');
        if (BusinessWorkspace::isPersonal($id)) {
            $user = $request->user();
            abort_unless($id === BusinessWorkspace::personalId($user->id), 403);

            if (! $request->isMethodSafe()) {
                BusinessWorkspace::ensurePersonal($user);
            }
        }

        return $next($request);
    }
}
