<?php

namespace Laravel\Horizon\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Factory as Queue;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;

class RetryController extends Controller
{
    /**
     * Retry a failed job.
     *
     * @param  string  $id
     * @return array<string, mixed>
     */
    public function store($id, Queue $queue, JobRepository $jobs)
    {
        if ($job = $jobs->findFailed($id)) {
            $queue->connection($job->connection)->pushRaw(
                $this->preparePayload($retryId = (string) Str::uuid(), $job->payload),
                $job->queue
            );

            $jobs->storeRetryReference($id, $retryId);

            return ['retried' => true, 'source' => 'horizon', 'id' => $retryId];
        }

        $failer = app('queue.failer');

        if ($job = $failer->find($id)) {
            $queue->connection($job->connection)->pushRaw(
                $this->preparePayload((string) Str::uuid(), $job->payload),
                $job->queue
            );

            $failer->forget($id);

            return ['retried' => true, 'source' => 'queue', 'id' => $id];
        }

        abort(404, 'Failed job not found.');
    }

    /**
     * Prepare the payload for queueing.
     */
    protected function preparePayload(string $id, string $payload): string
    {
        $payload = json_decode($payload, true);

        if (! is_array($payload)) {
            $payload = [];
        }

        return json_encode(array_merge($payload, [
            'id' => $id,
            'uuid' => $id,
            'attempts' => 0,
            'retry_of' => $payload['uuid'] ?? $payload['id'] ?? null,
            'retryUntil' => $this->prepareNewTimeout($payload),
        ]));
    }

    /**
     * Prepare the timeout.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function prepareNewTimeout(array $payload): ?int
    {
        $retryUntil = $payload['retryUntil'] ?? $payload['timeoutAt'] ?? null;
        $pushedAt = $payload['pushedAt'] ?? microtime(true);

        return $retryUntil
            ? CarbonImmutable::now()->addSeconds(ceil($retryUntil - $pushedAt))->getTimestamp()
            : null;
    }
}
