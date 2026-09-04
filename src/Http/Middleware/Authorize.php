<?php

namespace Iocod\Yardmaster\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the dashboard behind a gate.
 *
 * Two gates, not one. Viewing a queue and emptying one are different
 * permissions, and collapsing them means anyone who can look at the dashboard
 * can destroy a production queue.
 */
class Authorize
{
    public function __construct(protected Gate $gate) {}

    public function handle(Request $request, Closure $next, string $ability = 'viewYardmaster'): Response
    {
        if (! $this->gate->allows($ability, [$request->user()])) {
            abort(403, 'You are not authorized to access Yardmaster.');
        }

        return $next($request);
    }
}
