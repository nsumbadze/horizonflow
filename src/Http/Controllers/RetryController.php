<?php

namespace Laravel\Horizon\Http\Controllers;

use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Jobs\RetryFailedJob;

class RetryController extends Controller
{
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
