<?php

namespace Laravel\Horizon\Http\Middleware;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Exceptions\ForbiddenException;
use Laravel\Horizon\Horizon;

/**
 * Authorize mutation routes (retry, pause/continue supervisors).
 *
 * The default {@see Authenticate} middleware ensures the request is allowed
 * into the dashboard at all (viewHorizon). This middleware adds a second
 * check for `controlHorizon` so an operator can be granted read-only access
 * while reserving destructive operations for trusted users.
 *
 * If `controlHorizon` is not defined, the check is a no-op — keeping the
 * upstream behavior where any viewer can also mutate state.
 */
class AuthenticateControl
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        if (! Horizon::check($request)) {
            throw ForbiddenException::make();
        }

        if (Gate::has('controlHorizon')
            && ! Gate::forUser($request->user())->check('controlHorizon')) {
            throw ForbiddenException::make();
        }

        return $next($request);
    }
}
