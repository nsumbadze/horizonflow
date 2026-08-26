<?php

namespace Laravel\Horizon\Listeners;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobProcessing;
use Laravel\Horizon\Contracts\JobControlRepository;
use Laravel\Horizon\Exceptions\CancellationStateUnavailableException;
use Laravel\Horizon\JobRunInspector;
use Throwable;

/**
 * Drop a job whose run has been cancelled, before it runs.
 *
 * The worker raises JobProcessing, then returns early if the job has been
 * deleted, so deleting it here stops handle() without failing the job. This
 * is what ends a self-chained walk: the running job may still queue its
 * successor, but that successor is dropped the moment a worker picks it up.
 *
 * The two registry lookups fail in deliberately different directions:
 *
 * - Every job in the application passes the first one, so an unreadable
 *   registry there lets jobs run as they would without this listener. Failing
 *   closed would turn a Horizon metadata blip into a queue-wide outage.
 * - The second is only reached for a job that belongs to a run while some run
 *   is already cancelled, having just read the registry successfully. Failing
 *   there is a genuine anomaly, so the job is deferred rather than run — an
 *   operator has asked for this work to stop, and a deferred job is
 *   recoverable in a way that work already performed is not.
 *
 * Either failure is reported rather than swallowed, so a persistent blind
 * spot is visible instead of silent.
 */
class DropCancelledRunJobs
{
    /**
     * Create a new listener instance.
     *
     * @return void
     */
    public function __construct(
        protected JobControlRepository $controls,
        protected JobRunInspector $runs,
        protected ExceptionHandler $exceptions,
    ) {
    }

    /**
     * Handle the event.
     *
     * @return void
     *
     * @throws \Laravel\Horizon\Exceptions\CancellationStateUnavailableException
     */
    public function handle(JobProcessing $event)
    {
        $job = $event->job;

        if ($job->isDeleted() || $job->isReleased()) {
            return;
        }

        try {
            if (! $this->controls->anyRunCancelled()) {
                return;
            }
        } catch (Throwable $e) {
            $this->exceptions->report($e);

            return;
        }

        $payload = $job->payload();

        if (! is_array($payload)) {
            return;
        }

        // Never throws: a job with no readable run simply has no run.
        $group = $this->runs->groupForPayload($payload);

        if ($group === null) {
            return;
        }

        try {
            if (! $this->controls->runCancelled($group)) {
                return;
            }
        } catch (Throwable $e) {
            $this->exceptions->report($e);

            if ($this->deferWhenUnknown()) {
                throw new CancellationStateUnavailableException(
                    "Could not determine whether the [{$group}] run is cancelled, so the job was not run."
                );
            }

            return;
        }

        $this->recordDrop($group, $payload['id'] ?? null);

        $job->delete();
    }

    /**
     * Record the drop without letting bookkeeping decide whether it happens.
     *
     * @param  mixed  $id
     * @return void
     */
    protected function recordDrop(string $group, $id)
    {
        try {
            $this->controls->recordRunDrop($group, is_string($id) ? $id : null);
        } catch (Throwable $e) {
            $this->exceptions->report($e);
        }
    }

    /**
     * Determine whether an unreadable run should hold the job back.
     *
     * @return bool
     */
    protected function deferWhenUnknown()
    {
        return config('horizonxflow.cancellation.on_lookup_failure', 'defer') !== 'run';
    }
}
