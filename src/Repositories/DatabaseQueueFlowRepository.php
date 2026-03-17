<?php

namespace Laravel\Horizon\Repositories;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\QueueFlowRepository;

class DatabaseQueueFlowRepository implements QueueFlowRepository
{
    /**
     * Get queue flow data from Laravel database queue tables.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $queues = collect($this->connections())
            ->flatMap(fn (array $connection): array => $this->queuesForConnection($connection))
            ->sortBy(['connection', 'name'])
            ->values();

        $failed = $this->failedCount();

        return [
            'source' => 'database',
            'generated_at' => Carbon::now()->toJSON(),
            'summary' => [
                'pending' => $queues->sum('pending'),
                'processing' => null,
                'completed' => null,
                'failed' => $failed,
                'delayed' => $queues->sum('delayed'),
                'throughput_per_minute' => null,
                'average_wait_seconds' => (int) round($queues->avg('wait_seconds') ?? 0),
                'connections' => $queues->pluck('connection')->unique()->values()->all(),
            ],
            'nodes' => $this->nodes($queues->all(), $failed),
            'edges' => $this->edges($queues->all(), $failed),
            'queues' => $queues->all(),
            'events' => [
                ['status' => 'healthy', 'label' => 'Database queue tables scanned'],
                ['status' => 'warning', 'label' => 'Processing counts require worker telemetry'],
            ],
        ];
    }

    /**
     * @return array<int, array{connection: string, database: string|null, table: string, queue: string|null}>
     */
    protected function connections(): array
    {
        $configured = config('horizonxbrain.flow.database.connections', []);

        if ($configured !== []) {
            return collect($configured)->map(fn (array $connection, string $name): array => [
                'connection' => $name,
                'database' => $connection['connection'] ?? null,
                'table' => $connection['table'] ?? 'jobs',
                'queue' => $connection['queue'] ?? null,
            ])->values()->all();
        }

        return collect(config('queue.connections', []))
            ->filter(fn (array $connection): bool => ($connection['driver'] ?? null) === 'database')
            ->map(fn (array $connection, string $name): array => [
                'connection' => $name,
                'database' => $connection['connection'] ?? null,
                'table' => $connection['table'] ?? 'jobs',
                'queue' => $connection['queue'] ?? null,
            ])->values()->all();
    }

    /**
     * @param  array{connection: string, database: string|null, table: string, queue: string|null}  $connection
     * @return array<int, array<string, mixed>>
     */
    protected function queuesForConnection(array $connection): array
    {
        try {
            $rows = DB::connection($connection['database'])
                ->table($connection['table'])
                ->selectRaw('queue, count(*) as pending, sum(case when available_at > ? then 1 else 0 end) as delayed, min(available_at) as oldest_available_at', [Carbon::now()->timestamp])
                ->when($connection['queue'], fn ($query, string $queue) => $query->where('queue', $queue))
                ->groupBy('queue')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        return $rows->map(function (object $row) use ($connection): array {
            $oldestAvailableAt = $row->oldest_available_at ? (int) $row->oldest_available_at : Carbon::now()->timestamp;

            return [
                'connection' => $connection['connection'],
                'name' => $row->queue,
                'pending' => (int) $row->pending,
                'wait_seconds' => max(0, Carbon::now()->timestamp - $oldestAvailableAt),
                'processes' => null,
                'throughput_per_minute' => null,
                'delayed' => (int) $row->delayed,
                'driver' => 'database',
            ];
        })->all();
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

    protected function failedCount(): int
    {
        try {
            return DB::table(config('horizonxbrain.flow.database.failed_table', 'failed_jobs'))->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<string, mixed>  $queue
     */
    protected function queueId(array $queue): string
    {
        return 'queue-'.preg_replace('/[^a-z0-9]+/i', '-', strtolower($queue['connection'].'-'.$queue['name']));
    }

    /**
     * @param  array<string, mixed>  $queue
     */
    protected function status(array $queue): string
    {
        return match (true) {
            $queue['wait_seconds'] >= 30 || $queue['pending'] >= 500 => 'critical',
            $queue['wait_seconds'] >= 10 || $queue['pending'] >= 100 || $queue['delayed'] > 0 => 'warning',
            default => 'healthy',
        };
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
