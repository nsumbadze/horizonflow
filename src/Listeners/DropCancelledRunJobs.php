<?php

namespace Laravel\Horizon\Listeners;

use Illuminate\Queue\Events\JobProcessing;
use Laravel\Horizon\Contracts\JobControlRepository;
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
 * Reading a job's run means unserializing its command, so nothing is read at
 * all unless some run is actually cancelled.
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
    ) {
    }

    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle(JobProcessing $event)
    {
        try {
            if (! $this->controls->anyRunCancelled()) {
                return;
            }

            $payload = $event->job->payload();

            if (! is_array($payload)) {
                return;
            }

            $group = $this->runs->groupForPayload($payload);

            if ($group === null || ! $this->controls->runCancelled($group)) {
                return;
            }

            $id = $payload['id'] ?? null;

            $this->controls->recordRunDrop($group, is_string($id) ? $id : null);

            $event->job->delete();
        } catch (Throwable $e) {
            // A worker must never die because a cancellation lookup failed;
            // the job simply runs as it would have without this listener.
        }
    }
}
