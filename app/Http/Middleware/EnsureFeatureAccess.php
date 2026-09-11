<?php

namespace App\Http\Middleware;

use App\Services\FeatureAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards a route by the feature it belongs to, at the level the access table grants.
 *
 * `feature:finance` refuses anyone the table denies outright, and refuses a write from anyone it
 * only grants a read - which is what turns "samo pregled" from a hidden button into a rule. The
 * method decides which of the two applies, so one declaration covers a whole resource: GET and HEAD
 * are reads, everything else is a write.
 *
 * Pass `feature:finance,read` to require only visibility even for writes, where a resource is read
 * by one feature but written through another.
 */
class EnsureFeatureAccess
{
    public function handle(Request $request, Closure $next, string $feature, string $mode = 'auto'): Response
    {
        $user = $request->user();

        abort_unless(FeatureAccess::canAccess($user, $feature), 403, 'You do not have access to this section.');

        $writing = $mode === 'write'
            || ($mode === 'auto' && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true));

        abort_if($writing && ! FeatureAccess::canEdit($user, $feature), 403, 'You have view-only access to this section.');

        return $next($request);
    }
}
