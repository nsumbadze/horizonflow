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
                $failure = $failed[$this->queueKey($queue)] ?? [
                    'failed' => 0,
                    'latest_error' => null,
                    'last_failed_at' => null,
                    'jobs' => [],
                    'job_classes' => [],
                ];

                return array_merge($queue, [
                    'failed' => $failure['failed'],
                    'latest_error' => $failure['latest_error'],
                    'last_failed_at' => $failure['last_failed_at'],
                    'jobs' => $this->mergeRecentJobs($queue['jobs'] ?? [], $failure['jobs']),
                    'job_classes' => $this->mergeJobClasses($queue['job_classes'] ?? [], $failure['job_classes']),
                    'failure_rate' => null,
                ]);
            })
            ->sortBy(fn (array $queue): string => $queue['connection'].':'.$queue['name'])
            ->values();

        $failedCount = $queues->sum('failed');
        $window = $this->windowSeconds();

        return [
            'source' => 'database',
            'meta' => $this->metadata(),
            'generated_at' => Carbon::now()->toJSON(),
            'summary' => [
                'pending' => $queues->sum('pending'),
                'processing' => null,
                'completed' => null,
                'failed' => $failedCount,
                'failed_in_window' => $this->failedInWindow($connections, $window),
                'window_seconds' => $window,
                'delayed' => $queues->sum('delayed'),
                'throughput_per_minute' => null,
                'current_throughput_per_minute' => 0,
                'average_wait_seconds' => (int) round($queues->avg('wait_seconds') ?? 0),
                'connections' => $queues->pluck('connection')->unique()->values()->all(),
            ],
            'nodes' => $this->nodes($queues->all(), $failedCount),
            'edges' => $this->edges($queues->all(), $failedCount),
            'queues' => $queues->all(),
            'events' => $this->events($queues->all()),
        ];
    }

    /**
     * @return array<int, array{connection: string, queue_connection: string, database: string|null, table: string, queue: string|null}>
     */
    protected function connections(): array
    {
        $configured = config('horizonxflow.flow.database.connections', []);

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

        if (! config('horizonxflow.flow.database.discover_connections', true)) {
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
            $pendingJobs = $this->pendingJobsForConnection($connection)->groupBy('queue');

            $rows = DB::connection($connection['database'])
                ->table($connection['table'])
                ->selectRaw('queue, count(*) as pending, sum(case when available_at > ? then 1 else 0 end) as delayed, min(case when available_at <= ? then available_at else null end) as oldest_available_at, max(attempts) as attempts', [Carbon::now()->timestamp, Carbon::now()->timestamp])
                ->when($connection['queue'], fn ($query, string $queue) => $query->where('queue', $queue))
                ->groupBy('queue')
                ->get();
        } catch (Throwable) {
            return [];
        }

        $queues = $rows->map(function (object $row) use ($connection, $pendingJobs): array {
            $oldestAvailableAt = $row->oldest_available_at ? (int) $row->oldest_available_at : Carbon::now()->timestamp;
            $pending = (int) $row->pending;
            $jobs = $pendingJobs->get($row->queue, collect())->values()->all();

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
                'jobs' => $jobs,
                'job_classes' => $this->jobClasses($jobs),
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
            'jobs' => [],
            'job_classes' => [],
            'driver' => 'database',
            'source' => $connection['connection'],
        ];
    }

    /**
     * @param  array<int, array{connection: string, queue_connection: string, database: string|null, table: string, queue: string|null}>  $connections
     * @return array<string, array{failed: int, latest_error: string|null, last_failed_at: string|null, jobs: array<int, array<string, mixed>>, job_classes: array<int, array<string, mixed>>}>
     */
    protected function failedByQueue(array $connections): array
    {
        return collect($connections)
            ->flatMap(fn (array $connection): Collection => $this->failedForConnection($connection))
            ->groupBy(fn (array $failure): string => $this->queueKey($failure))
            ->map(function (Collection $failures): array {
                $jobs = $failures->pluck('jobs')->flatten(1)->values()->all();

                return [
                    'failed' => $failures->sum('failed'),
                    'latest_error' => $failures->pluck('latest_error')->filter()->first(),
                    'last_failed_at' => $failures->pluck('last_failed_at')->filter()->sortDesc()->first(),
                    'jobs' => $jobs,
                    'job_classes' => $this->jobClasses($jobs),
                ];
            })
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
                ->table(config('horizonxflow.flow.database.failed_table', 'failed_jobs'))
                ->select('*')
                ->whereIn('connection', [$connection['queue_connection'], $connection['connection']])
                ->when($connection['queue'], fn ($query, string $queue) => $query->where('queue', $queue))
                ->orderByDesc('failed_at')
                ->limit(100)
                ->get()
                ->map(function (object $row) use ($connection): array {
                    $job = $this->failedJobSummary($row, $connection);

                    return [
                        'connection' => $connection['connection'],
                        'queue_connection' => $row->connection,
                        'storage_connection' => $connection['database'],
                        'name' => $row->queue,
                        'failed' => 1,
                        'latest_error' => $this->exceptionSummary($row->exception ?? null),
                        'last_failed_at' => (string) ($row->failed_at ?? ''),
                        'jobs' => [$job],
                        'job_classes' => $this->jobClasses([$job]),
                    ];
                });
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * Count failed jobs whose `failed_at` falls within the request window.
     *
     * Aggregates across all configured database connections, dedup-ing by
     * `database` so the same `failed_jobs` table isn't counted twice when
     * multiple queue connections share a storage connection.
     *
     * @param  array<int, array{connection: string, queue_connection: string, database: string|null, table: string, queue: string|null}>  $connections
     */
    protected function failedInWindow(array $connections, int $window): ?int
    {
        $since = Carbon::now()->subSeconds($window);
        $table = config('horizonxflow.flow.database.failed_table', 'failed_jobs');
        $total = 0;
        $any = false;

        foreach (collect($connections)->unique(fn (array $c): string => (string) ($c['database'] ?? '')) as $connection) {
            try {
                $total += (int) DB::connection($connection['database'])
                    ->table($table)
                    ->where('failed_at', '>=', $since)
                    ->when($connection['queue'], fn ($query, string $queue) => $query->where('queue', $queue))
                    ->count();
                $any = true;
            } catch (Throwable) {
                continue;
            }
        }

        return $any ? $total : null;
    }

    protected function windowSeconds(): int
    {
        return min(2592000, max(60, (int) request()->query('window', 900)));
    }

    /**
     * @param  array{connection: string, queue_connection: string, database: string|null, table: string, queue: string|null}  $connection
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function pendingJobsForConnection(array $connection): Collection
    {
        try {
            return DB::connection($connection['database'])
                ->table($connection['table'])
                ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
                ->when($connection['queue'], fn ($query, string $queue) => $query->where('queue', $queue))
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(fn (object $row): array => $this->pendingJobSummary($row, $connection));
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * @param  array{connection: string, queue_connection: string, database: string|null, table: string, queue: string|null}  $connection
     * @return array<string, mixed>
     */
    protected function pendingJobSummary(object $row, array $connection): array
    {
        $createdAt = (int) ($row->created_at ?? Carbon::now()->timestamp);
        $reservedAt = (int) ($row->reserved_at ?? 0);
        $availableAt = (int) ($row->available_at ?? 0);

        return [
            'id' => 'database-'.$connection['connection'].'-'.$row->id,
            'name' => $this->payloadName($row->payload ?? null),
            'status' => $reservedAt > 0 ? 'reserved' : 'pending',
            'connection' => $connection['queue_connection'],
            'queue' => (string) ($row->queue ?? 'default'),
            'attempts' => (int) ($row->attempts ?? 0),
            'runtime_seconds' => null,
            'age_seconds' => max(0, Carbon::now()->timestamp - $createdAt),
            'available_in_seconds' => max(0, $availableAt - Carbon::now()->timestamp),
            'timestamp' => max($reservedAt, $createdAt),
            'exception' => null,
            'inspectable' => false,
            'retryable' => false,
        ];
    }

    /**
     * @param  array{connection: string, queue_connection: string, database: string|null, table: string, queue: string|null}  $connection
     * @return array<string, mixed>
     */
    protected function failedJobSummary(object $row, array $connection): array
    {
        return [
            'id' => (string) ($row->uuid ?? $row->id ?? sha1((string) ($row->payload ?? '').(string) ($row->failed_at ?? ''))),
            'name' => $this->payloadName($row->payload ?? null),
            'status' => 'failed',
            'connection' => (string) ($row->connection ?? $connection['queue_connection']),
            'queue' => (string) ($row->queue ?? 'default'),
            'attempts' => 0,
            'runtime_seconds' => null,
            'age_seconds' => null,
            'timestamp' => $this->timestampFrom($row->failed_at ?? null),
            'exception' => $this->exceptionSummary($row->exception ?? null),
            'inspectable' => false,
            'retryable' => true,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $jobs
     * @return array<int, array<string, mixed>>
     */
    protected function jobClasses(array $jobs): array
    {
        return collect($jobs)
            ->groupBy(fn (array $job): string => (string) $job['name'])
            ->map(fn (Collection $jobs, string $name): array => [
                'name' => $name,
                'pending' => $jobs->where('status', 'pending')->count(),
                'reserved' => $jobs->where('status', 'reserved')->count(),
                'completed' => $jobs->where('status', 'completed')->count(),
                'failed' => $jobs->where('status', 'failed')->count(),
                'attempts' => $jobs->max('attempts') ?? 0,
                'latest_error' => $jobs->pluck('exception')->filter()->first(),
            ])
            ->sortByDesc(fn (array $class): int => (int) $class['failed'] + (int) $class['pending'] + (int) $class['reserved'])
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @param  array<int, array<string, mixed>>  $incoming
     * @return array<int, array<string, mixed>>
     */
    protected function mergeRecentJobs(array $existing, array $incoming): array
    {
        return collect($existing)
            ->merge($incoming)
            ->unique(fn (array $job): string => (string) $job['id'])
            ->sortByDesc(fn (array $job): int => (int) ($job['timestamp'] ?? 0))
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @param  array<int, array<string, mixed>>  $incoming
     * @return array<int, array<string, mixed>>
     */
    protected function mergeJobClasses(array $existing, array $incoming): array
    {
        $classes = [];

        foreach ([$existing, $incoming] as $group) {
            foreach ($group as $class) {
                $name = (string) ($class['name'] ?? 'Queued job');
                $classes[$name] ??= [
                    'name' => $name,
                    'pending' => 0,
                    'reserved' => 0,
                    'completed' => 0,
                    'failed' => 0,
                    'attempts' => 0,
                    'latest_error' => null,
                ];

                foreach (['pending', 'reserved', 'completed', 'failed'] as $state) {
                    $classes[$name][$state] += (int) ($class[$state] ?? 0);
                }

                $classes[$name]['attempts'] = max((int) $classes[$name]['attempts'], (int) ($class['attempts'] ?? 0));
                $classes[$name]['latest_error'] ??= $class['latest_error'] ?? null;
            }
        }

        return collect($classes)
            ->sortByDesc(fn (array $class): int => (int) $class['failed'] + (int) $class['pending'] + (int) $class['reserved'] + (int) $class['completed'])
            ->take(8)
            ->values()
            ->all();
    }

    protected function payloadName(mixed $payload): string
    {
        if (! is_string($payload) || $payload === '') {
            return 'Queued job';
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return 'Queued job';
        }

        return (string) ($decoded['displayName'] ?? $decoded['data']['commandName'] ?? $decoded['job'] ?? 'Queued job');
    }

    protected function timestampFrom(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        try {
            return Carbon::parse((string) $value)->timestamp;
        } catch (Throwable) {
            return null;
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

    /**
     * @param  array<int, array<string, mixed>>  $queues
     * @return array<int, array<string, mixed>>
     */
    protected function events(array $queues): array
    {
        $events = collect($queues)
            ->flatMap(fn (array $queue): array => collect($queue['jobs'] ?? [])
                ->map(fn (array $job): array => $this->event($queue, $job))
                ->all())
            ->sortByDesc(fn (array $event): int => (int) ($event['timestamp'] ?? 0))
            ->take(40)
            ->values();

        if ($events->isNotEmpty()) {
            return $events->all();
        }

        return [[
            'status' => 'healthy',
            'label' => 'Database queue tables scanned: '.collect($queues)->pluck('connection')->unique()->implode(', '),
        ]];
    }

    /**
     * @param  array<string, mixed>  $queue
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    protected function event(array $queue, array $job): array
    {
        $state = (string) ($job['status'] ?? 'pending');

        return [
            'id' => (string) ($job['id'] ?? sha1((string) ($job['name'] ?? 'Queued job').(string) ($job['timestamp'] ?? ''))),
            'status' => match ($state) {
                'failed' => 'critical',
                'reserved', 'pending' => 'warning',
                default => 'healthy',
            },
            'state' => $state,
            'connection' => (string) ($job['connection'] ?? $queue['connection']),
            'queue' => (string) ($job['queue'] ?? $queue['name']),
            'job' => (string) ($job['name'] ?? 'Queued job'),
            'timestamp' => $job['timestamp'] ?? null,
            'result' => match ($state) {
                'failed' => 'failed',
                'reserved' => 'workers',
                'completed' => 'completed',
                default => 'queue',
            },
            'label' => sprintf('%s on %s:%s is %s',
                $job['name'] ?? 'Queued job',
                $job['connection'] ?? $queue['connection'],
                $job['queue'] ?? $queue['name'],
                $state
            ),
        ];
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
