<?php

namespace Laravel\Horizon\QueueFlow;

use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\QueueFlowRepository;

class QueueFlowQueuesBuilder
{
    /**
     * Whitelist of columns that may be used for `sort`.
     */
    protected const SORTABLE_COLUMNS = [
        'name',
        'connection',
        'driver',
        'pending',
        'delayed',
        'wait_seconds',
        'oldest_pending_seconds',
        'failed',
        'failure_rate',
        'current_throughput_per_minute',
        'throughput_per_minute',
        'processes',
    ];

    public function __construct(
        protected QueueFlowRepository $repository,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?string $filter = null, ?string $sort = null, ?string $direction = null, int $limit = 200): array
    {
        $payload = $this->repository->get();
        $queues = collect($payload['queues'] ?? []);
        $total = $queues->count();

        if ($filter !== null && trim($filter) !== '') {
            $queues = $this->filter($queues, $filter);
        }

        if ($sort !== null) {
            $queues = $this->sort($queues, $sort, $direction);
        }

        if ($limit > 0) {
            $queues = $queues->take($limit);
        }

        return [
            'source' => $payload['source'] ?? null,
            'errors' => $payload['errors'] ?? [],
            'generated_at' => $payload['generated_at'] ?? null,
            'total' => $total,
            'returned' => $queues->count(),
            'queues' => array_map(
                fn (array $queue): array => $this->stripJobs($queue),
                $queues->values()->all()
            ),
        ];
    }

    protected function filter(Collection $queues, string $filter): Collection
    {
        $needle = strtolower(trim($filter));

        return $queues->filter(function (array $queue) use ($needle): bool {
            foreach (['name', 'connection', 'driver', 'source', 'storage_connection'] as $field) {
                $value = $queue[$field] ?? null;

                if (is_string($value) && str_contains(strtolower($value), $needle)) {
                    return true;
                }
            }

            return false;
        });
    }

    protected function sort(Collection $queues, string $sort, ?string $direction): Collection
    {
        if (! in_array($sort, self::SORTABLE_COLUMNS, true)) {
            return $queues;
        }

        $descending = strtolower((string) $direction) === 'desc';

        return $queues->sortBy(
            fn (array $queue): int|float|string => $queue[$sort] ?? 0,
            SORT_REGULAR,
            $descending
        )->values();
    }

    /**
     * Strip per-job arrays so the queue list response stays cheap; clients
     * fetch job detail via the queue-jobs endpoint.
     *
     * @param  array<string, mixed>  $queue
     * @return array<string, mixed>
     */
    protected function stripJobs(array $queue): array
    {
        unset($queue['jobs']);
        unset($queue['job_classes']);

        return $queue;
    }
}
