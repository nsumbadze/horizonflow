<?php

namespace Laravel\Horizon\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Factory as Queue;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\JobParameterInspector;

class RetryFailedJob
{
    /**
     * Create a new job instance.
     *
     * @param  string  $id  The job ID.
     * @param  array<string, mixed>  $parameters  The job parameters to override before retrying.
     * @return void
     */
    public function __construct(
        public $id,
        public array $parameters = [],
    ) {
    }

    /**
     * Execute the job.
     *
     * @param  \Illuminate\Contracts\Queue\Factory  $queue
     * @param  \Laravel\Horizon\Contracts\JobRepository  $jobs
     * @param  \Laravel\Horizon\JobParameterInspector  $inspector
     * @return void
     */
    public function handle(Queue $queue, JobRepository $jobs, JobParameterInspector $inspector)
    {
        if (is_null($job = $jobs->findFailed($this->id))) {
            return;
        }

        $queue->connection($job->connection)->pushRaw(
            $this->preparePayload($id = Str::uuid(), $job->payload, $inspector), $job->queue
        );

        $jobs->storeRetryReference($this->id, $id);
    }

    /**
     * Prepare the payload for queueing.
     *
     * @param  string  $id
     * @param  string  $payload
     * @param  \Laravel\Horizon\JobParameterInspector  $inspector
     * @return string
     */
    protected function preparePayload($id, $payload, JobParameterInspector $inspector)
    {
        $payload = $inspector->applyOverrides(json_decode($payload, true), $this->parameters);

        return json_encode(array_merge($payload, [
            'id' => $id,
            'uuid' => $id,
            'attempts' => 0,
            'retry_of' => $this->id,
            'retryUntil' => $this->prepareNewTimeout($payload),
        ]));
    }

    /**
     * Prepare the timeout.
     *
     * @param  array  $payload
     * @return int|null
     */
    protected function prepareNewTimeout($payload)
    {
        $retryUntil = $payload['retryUntil'] ?? $payload['timeoutAt'] ?? null;

        $pushedAt = $payload['pushedAt'] ?? microtime(true);

        return $retryUntil
            ? CarbonImmutable::now()->addSeconds(ceil($retryUntil - $pushedAt))->getTimestamp()
            : null;
    }
}
