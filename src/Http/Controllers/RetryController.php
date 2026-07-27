<?php

namespace Laravel\Horizon\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Exceptions\InvalidJobParameterException;
use Laravel\Horizon\Http\Middleware\AuthenticateControl;
use Laravel\Horizon\JobParameterInspector;
use Laravel\Horizon\Jobs\RetryFailedJob;

class RetryController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(AuthenticateControl::class);
    }

    /**
     * Get the parameters that may be overridden when retrying a failed job.
     *
     * @param  string  $id
     * @return array<string, mixed>
     */
    public function parameters($id, JobRepository $jobs, JobParameterInspector $inspector)
    {
        if (is_null($job = $jobs->findFailed($id))) {
            abort(404, 'Failed job not found.');
        }

        return $inspector->inspect($this->decodePayload($job));
    }

    /**
     * Retry a failed job.
     *
     * @param  string  $id
     * @return void
     */
    public function store($id, Request $request, JobRepository $jobs, JobParameterInspector $inspector)
    {
        if (is_null($job = $jobs->findFailed($id))) {
            abort(404, 'Failed job not found.');
        }

        $parameters = $this->parameterOverrides($request);

        if ($parameters !== []) {
            $this->ensureParametersMayBeApplied($job, $parameters, $inspector);
        }

        dispatch(new RetryFailedJob($id, $parameters));
    }

    /**
     * Get the parameter overrides from the request.
     *
     * @return array<string, mixed>
     */
    protected function parameterOverrides(Request $request)
    {
        $parameters = $request->input('parameters', []);

        if (! is_array($parameters)) {
            abort(422, 'The job parameters must be given as an object.');
        }

        return $parameters;
    }

    /**
     * Ensure the given overrides may be applied to the failed job.
     *
     * @param  object  $job
     * @param  array<string, mixed>  $parameters
     * @return void
     */
    protected function ensureParametersMayBeApplied($job, array $parameters, JobParameterInspector $inspector)
    {
        try {
            $inspector->applyOverrides($this->decodePayload($job), $parameters);
        } catch (InvalidJobParameterException $e) {
            abort(422, $e->getMessage());
        }
    }

    /**
     * Decode the payload of the given failed job.
     *
     * @param  object  $job
     * @return array<string, mixed>
     */
    protected function decodePayload($job)
    {
        return json_decode($job->payload ?? '', true) ?: [];
    }
}
