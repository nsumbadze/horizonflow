<?php

namespace Laravel\Horizon\QueueFlow;

use Laravel\Horizon\Contracts\QueueFlowRepository;

class QueueFlowQueueJobsBuilder
{
    public function __construct(
        protected QueueFlowRepository $repository,
    ) {
        //
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $key): ?array
    {
        $payload = $this->repository->get();

        foreach ($payload['queues'] ?? [] as $queue) {
            if ($this->matches($queue, $key)) {
                return [
                    'generated_at' => $payload['generated_at'] ?? null,
                    'queue' => [
                        'name' => $queue['name'] ?? null,
                        'connection' => $queue['connection'] ?? null,
                        'driver' => $queue['driver'] ?? null,
                        'source' => $queue['source'] ?? null,
                        'storage_connection' => $queue['storage_connection'] ?? null,
                    ],
                    'jobs' => array_values($queue['jobs'] ?? []),
                    'job_classes' => array_values($queue['job_classes'] ?? []),
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $queue
     */
    protected function matches(array $queue, string $key): bool
    {
        $candidates = [
            $this->canonicalKey($queue),
            ($queue['driver'] ?? '').':'.($queue['name'] ?? ''),
            ($queue['connection'] ?? '').':'.($queue['name'] ?? ''),
            (string) ($queue['name'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && strcasecmp($candidate, $key) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $queue
     */
    protected function canonicalKey(array $queue): string
    {
        return implode(':', [
            (string) ($queue['driver'] ?? 'unknown'),
            (string) ($queue['connection'] ?? 'unknown'),
            (string) ($queue['name'] ?? 'default'),
        ]);
    }
}
