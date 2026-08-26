<?php

namespace Laravel\Horizon\Concerns;

use Laravel\Horizon\Contracts\JobControlRepository;

trait InteractsWithCancellation
{
    /**
     * The run this job belongs to, if any.
     *
     * Jobs that are part of one logical run — a chained walk, or a fan-out of
     * related work — should return the same key here so an operator can stop
     * all of them at once:
     *
     * public function cancellationGroup(): ?string
     * {
     *     return "citrus-sync:{$this->companyId}";
     * }
     */
    public function cancellationGroup(): ?string
    {
        return null;
    }

    /**
     * Determine whether an operator requested cancellation of this job.
     *
     * True when this job was cancelled on its own, or when the run it belongs
     * to was cancelled.
     */
    public function cancellationRequested(): bool
    {
        $id = $this->horizonJobId();
        $group = $this->cancellationGroup();
        $group = is_string($group) && $group !== '' ? $group : null;

        // Nothing to look up, so nothing is resolved. A job running outside a
        // worker has neither an id nor, usually, a run.
        if ($id === null && $group === null) {
            return false;
        }

        $controls = app(JobControlRepository::class);

        if ($id !== null && $controls->cancellationRequested($id)) {
            return true;
        }

        return $group !== null && $controls->runCancelled($group);
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
        if (! $this->cancellationRequested()) {
            return false;
        }

        if (($id = $this->horizonJobId()) !== null) {
            app(JobControlRepository::class)->acknowledgeCancellation($id);
        }

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
