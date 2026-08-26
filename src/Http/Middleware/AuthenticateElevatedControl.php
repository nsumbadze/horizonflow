<?php

namespace Laravel\Horizon\Http\Middleware;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Exceptions\ForbiddenException;
use Laravel\Horizon\Horizon;

/**
 * Authorize the controls that run or stop application code.
 *
 * {@see AuthenticateControl} lets local and testing environments through when
 * `controlHorizon` is undefined, which is convenient for pausing a queue or
 * retrying a job. Dispatching constructs and queues an application job with
 * operator-supplied arguments, and cancelling a run stops work across every
 * queue — capabilities that must never hinge on `APP_ENV`, because an
 * application deployed with `APP_ENV=local` would hand them to anyone who can
 * reach the dashboard.
 *
 * So there is no environment fallback here: `controlHorizon` must be defined
 * and must pass.
 */
class AuthenticateElevatedControl
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

        if (! Gate::has('controlHorizon')) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(
                403,
                'This control requires the controlHorizon gate. Define it in HorizonApplicationServiceProvider::gate() to enable it for a trusted subset of users.'
            );
        }

        if (! Gate::forUser($request->user())->check('controlHorizon')) {
            throw ForbiddenException::make();
        }

        return $next($request);
    }
}
