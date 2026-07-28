<?php

namespace Laravel\Horizon\Concerns;

use Laravel\Horizon\Contracts\JobControlRepository;

trait InteractsWithCancellation
{
    /**
     * Determine whether an operator requested cancellation of this job.
     */
    public function cancellationRequested(): bool
    {
        $id = $this->horizonJobId();

        return $id !== null
            && app(JobControlRepository::class)->cancellationRequested($id);
    }

    /**
     * Acknowledge cancellation and tell the caller to return from handle().
     *
     * Jobs should call this between idempotent units of work:
     *
     * if ($this->cancelIfRequested()) {
     *     return;
     * }
     */
    public function cancelIfRequested(): bool
    {
        $id = $this->horizonJobId();

        if ($id === null || ! app(JobControlRepository::class)->cancellationRequested($id)) {
            return false;
        }

        app(JobControlRepository::class)->acknowledgeCancellation($id);

        return true;
    }

    protected function horizonJobId(): ?string
    {
        if (! isset($this->job) || ! is_object($this->job) || ! method_exists($this->job, 'getJobId')) {
            return null;
        }

        $id = $this->job->getJobId();

        return is_string($id) && $id !== '' ? $id : null;
    }
}
