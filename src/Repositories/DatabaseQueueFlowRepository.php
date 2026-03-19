<?php

namespace Laravel\Horizon\Repositories;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\QueueFlowRepository;
use Throwable;

class DatabaseQueueFlowRepository implements QueueFlowRepository
{
    use BuildsQueueFlowMetadata;

    /**
     * Get queue flow data from Laravel database queue tables.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $connections = $this->connections();
        $failed = $this->failedByQueue($connections);

        $queues = collect($connections)
            ->flatMap(fn (array $connection): array => $this->queuesForConnection($connection))
            ->map(function (array $queue) use ($failed): array {
                $failure = $failed[$this->queueKey($queue)] ?? ['failed' => 0, 'latest_error' => null, 'last_failed_at' => null];

                return [
                    ...$queue,
                    'failed' => $failure['failed'],
                    'latest_error' => $failure['latest_error'],
                    'last_failed_at' => $failure['last_failed_at'],
                    'failure_rate' => null,
                ];
            })
            ->sortBy(fn (array $queue): string => $queue['connection'].':'.$queue['name'])
            ->values();

        $failedCount = $queues->sum('failed');

        return [
            'source' => 'database',
            'meta' => $this->metadata(),
            'generated_at' => Carbon::now()->toJSON(),
            'summary' => [
                'pending' => $queues->sum('pending'),
                'processing' => null,
                'completed' => null,
                'failed' => $failedCount,
                'delayed' => $queues->sum('delayed'),
                'throughput_per_minute' => null,
                'current_throughput_per_minute' => 0,
                'average_wait_seconds' => (int) round($queues->avg('wait_seconds') ?? 0),
                'connections' => $queues->pluck('connection')->unique()->values()->all(),
            ],
            'nodes' => $this->nodes($queues->all(), $failedCount),
            'edges' => $this->edges($queues->all(), $failedCount),
            'queues' => $queues->all(),
            'events' => [
                ['status' => 'healthy', 'label' => 'Database queue tables scanned: '.$queues->pluck('connection')->unique()->implode(', ')],
                ['status' => 'warning', 'label' => 'Database queue processing counts require worker telemetry'],
            ],
        ];
    }

    /**
     * @return array<int, array{connection: string, queue_connection: string, database: string|null, table: string, queue: string|null}>
     */
    protected function connections(): array
    {
        $configured = config('horizonxbrain.flow.database.connections', []);

        if ($configured !== []) {
            return collect($configured)->map(fn (array $connection, string $name): array => [
                'connection' => $name,
                'queue_connection' => $connection['queue_connection'] ?? $name,
                'database' => $connection['connection'] ?? $name,
                'table' => $connection['table'] ?? 'jobs',
                'queue' => $connection['queue'] ?? null,
            ])->values()->all();
        }

        $queueConnections = collect(config('queue.connections', []))
            ->filter(fn (array $connection): bool => ($connection['driver'] ?? null) === 'database')
            ->map(fn (array $connection, string $name): array => [
                'connection' => $connection['connection'] ?? config('database.default', $name),
                'queue_connection' => $name,
                'database' => $connection['connection'] ?? config('database.default'),
                'table' => $connection['table'] ?? 'jobs',
                'queue' => $connection['queue'] ?? null,
            ]);

        if (! config('horizonxbrain.flow.database.discover_connections', true)) {
            return $queueConnections->unique(fn (array $connection): string => $this->connectionKey($connection))->values()->all();
        }

        $databaseConnections = collect(config('database.connections', []))
            ->filter(fn (array $connection): bool => in_array($connection['driver'] ?? null, ['mysql', 'pgsql', 'sqlite', 'sqlsrv'], true))
            ->map(fn (array $connection, string $name): array => [
                'connection' => $name,
                'queue_connection' => $name,
                'database' => $name,
                'table' => 'jobs',
                'queue' => null,
            ]);

        return $queueConnections
            ->merge($databaseConnections)
            ->unique(fn (array $connection): string => $this->connectionKey($connection))
            ->values()
            ->all();
    }

    /**
     * @param  array{connection: string, queue_connection: string, database: string|null, table: string, queue: string|null}  $connection
     * @return array<int, array<string, mixed>>
     */
    protected function queuesForConnection(array $connection): array
    {
        try {
            $rows = DB::connection($connection['database'])
                ->table($connection['table'])
                ->selectRaw('queue, count(*) as pending, sum(case when available_at > ? then 1 else 0 end) as delayed, min(case when available_at <= ? then available_at else null end) as oldest_available_at, max(attempts) as attempts', [Carbon::now()->timestamp, Carbon::now()->timestamp])
                ->when($connection['queue'], fn ($query, string $queue) => $query->where('queue', $queue))
                ->groupBy('queue')
                ->get();
        } catch (Throwable) {
            return [];
        }

        $queues = $rows->map(function (object $row) use ($connection): array {
            $oldestAvailableAt = $row->oldest_available_at ? (int) $row->oldest_available_at : Carbon::now()->timestamp;
            $pending = (int) $row->pending;

            return [
                'connection' => $connection['connection'],
                'queue_connection' => $connection['queue_connection'],
                'storage_connection' => $connection['database'],
                'name' => $row->queue,
                'pending' => $pending,
                'wait_seconds' => max(0, Carbon::now()->timestamp - $oldestAvailableAt),
                'oldest_pending_seconds' => max(0, Carbon::now()->timestamp - $oldestAvailableAt),
                'processes' => null,
                'throughput_per_minute' => null,
                'current_throughput_per_minute' => 0,
                'estimated_drain_seconds' => null,
                'attempts' => (int) ($row->attempts ?? 0),
                'completed' => null,
                'delayed' => (int) $row->delayed,
                'driver' => 'database',
                'source' => $connection['connection'],
            ];
        });

        if ($queues->isEmpty() && $connection['queue'] !== null) {
            return [$this->emptyQueue($connection, $connection['queue'])];
        }

        return $queues->all();
    }

    /**
     * @param  array{connection: string, queue_connection: string, database: string|null, table: string, queue: string|null}  $connection
     * @return array<string, mixed>
     */
    protected function emptyQueue(array $connection, string $queue): array
    {
        return [
            'connection' => $connection['connection'],
            'queue_connection' => $connection['queue_connection'],
            'storage_connection' => $connection['database'],
            'name' => $queue,
            'pending' => 0,
            'wait_seconds' => 0,
            'oldest_pending_seconds' => 0,
            'processes' => null,
            'throughput_per_minute' => null,
            'current_throughput_per_minute' => 0,
            'estimated_drain_seconds' => null,
            'attempts' => 0,
            'completed' => null,
            'delayed' => 0,
            'driver' => 'database',
            'source' => $connection['connection'],
        ];
    }

    /**
     * @param  array<int, array{connection: string, queue_connection: string, database: string|null, table: string, queue: string|null}>  $connections
     * @return array<string, array{failed: int, latest_error: string|null, last_failed_at: string|null}>
     */
    protected function failedByQueue(array $connections): array
    {
        return collect($connections)
            ->flatMap(fn (array $connection): Collection => $this->failedForConnection($connection))
            ->groupBy(fn (array $failure): string => $this->queueKey($failure))
            ->map(fn (Collection $failures): array => [
                'failed' => $failures->sum('failed'),
                'latest_error' => $failures->pluck('latest_error')->filter()->first(),
                'last_failed_at' => $failures->pluck('last_failed_at')->filter()->sortDesc()->first(),
            ])
            ->all();
    }

    /**
     * @param  array{connection: string, queue_connection: string, database: string|null, table: string, queue: string|null}  $connection
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function failedForConnection(array $connection): Collection
    {
        try {
            return DB::connection($connection['database'])
                ->table(config('horizonxbrain.flow.database.failed_table', 'failed_jobs'))
                ->select(['connection', 'queue', 'exception', 'failed_at'])
                ->whereIn('connection', [$connection['queue_connection'], $connection['connection']])
                ->when($connection['queue'], fn ($query, string $queue) => $query->where('queue', $queue))
                ->orderByDesc('failed_at')
                ->limit(100)
                ->get()
                ->map(fn (object $row): array => [
                    'connection' => $connection['connection'],
                    'queue_connection' => $row->connection,
                    'storage_connection' => $connection['database'],
                    'name' => $row->queue,
                    'failed' => 1,
                    'latest_error' => $this->exceptionSummary($row->exception ?? null),
                    'last_failed_at' => (string) ($row->failed_at ?? ''),
                ]);
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $queues
     * @return array<int, array<string, mixed>>
     */
    protected function nodes(array $queues, int $failed): array
    {
        $nodes = [
            $this->node('producer-app', 'producer', 'Application Events', 'healthy'),
            $this->node('database-table', 'queue-store', 'Database Queue Tables', 'healthy'),
            $this->node('workers', 'worker', 'Queue Workers', 'healthy'),
            $this->node('completed', 'result', 'completed', 'healthy'),
            $this->node('failed', 'result', 'failed', $failed > 0 ? 'critical' : 'healthy', ['failed' => $failed]),
        ];

        foreach ($queues as $queue) {
            $nodes[] = $this->node($this->queueId($queue), 'queue', $queue['name'], $this->status($queue), [
                'pending' => $queue['pending'],
                'wait' => $queue['wait_seconds'],
                'delayed' => $queue['delayed'],
                'failed' => $queue['failed'] ?? 0,
                'latest_error' => $queue['latest_error'] ?? null,
            ]);
        }

        return $nodes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $queues
     * @return array<int, array<string, mixed>>
     */
    protected function edges(array $queues, int $failed): array
    {
        $edges = [$this->edge('producer-app', 'database-table', 'healthy', 'insert', null)];

        foreach ($queues as $queue) {
            $queueId = $this->queueId($queue);
            $status = $this->status($queue);

            $edges[] = $this->edge('database-table', $queueId, $status, 'pending', null);
            $edges[] = $this->edge($queueId, 'workers', $status, 'reserve', null);
        }

        $edges[] = $this->edge('workers', 'completed', 'healthy', 'finish', null);

        if ($failed > 0) {
            $edges[] = $this->edge('workers', 'failed', 'critical', 'exception', null);
        }

        return $edges;
    }

    protected function connectionKey(array $connection): string
    {
        return implode(':', [
            $connection['database'] ?? config('database.default'),
            $connection['table'],
            $connection['queue'] ?? '*',
        ]);
    }

    /**
     * @param  array<string, mixed>  $queue
     */
    protected function queueId(array $queue): string
    {
        return 'queue-'.preg_replace('/[^a-z0-9]+/i', '-', strtolower($queue['driver'].'-'.$queue['connection'].'-'.$queue['name']));
    }

    /**
     * @param  array<string, mixed>  $queue
     */
    protected function queueKey(array $queue): string
    {
        return implode(':', [
            $queue['driver'] ?? 'database',
            $queue['connection'],
            $queue['name'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $queue
     */
    protected function status(array $queue): string
    {
        return match (true) {
            ($queue['failed'] ?? 0) > 0 => 'critical',
            $queue['wait_seconds'] >= 30 || $queue['pending'] >= 500 => 'critical',
            $queue['wait_seconds'] >= 10 || $queue['pending'] >= 100 || $queue['delayed'] > 0 => 'warning',
            default => 'healthy',
        };
    }

    protected function exceptionSummary(?string $exception): ?string
    {
        $exception = trim((string) $exception);

        if ($exception === '') {
            return null;
        }

        return Str::limit(strtok($exception, "\n") ?: $exception, 180);
    }

    /**
     * @return array<string, mixed>
     */
    protected function node(string $id, string $type, string $label, string $status, array $metrics = []): array
    {
        return compact('id', 'type', 'label', 'status', 'metrics');
    }

    /**
     * @return array<string, mixed>
     */
    protected function edge(string $source, string $target, string $status, string $label, ?int $rate): array
    {
        return [
            'id' => "{$source}-{$target}",
            'source' => $source,
            'target' => $target,
            'status' => $status,
            'label' => $label,
            'rate_per_minute' => $rate,
        ];
    }
}
