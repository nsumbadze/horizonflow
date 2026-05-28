<?php

namespace Laravel\Horizon\Http\Controllers;

use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Http\Middleware\AuthenticateControl;
use Laravel\Horizon\Jobs\RetryFailedJob;

class RetryController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(AuthenticateControl::class);
    }

    /**
     * Retry a failed job.
     *
     * @param  string  $id
     * @return void
     */
    public function store($id, JobRepository $jobs)
    {
        if ($jobs->findFailed($id) === null) {
            abort(404, 'Failed job not found.');
        }

        dispatch(new RetryFailedJob($id));
    }
}
