<?php

namespace Laravel\Horizon\Repositories;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\QueueFlowRepository;
use Throwable;

class UnifiedQueueFlowRepository implements QueueFlowRepository
{
    use BuildsQueueFlowMetadata;

    /**
     * Create a new unified queue flow repository.
     */
    public function __construct(
        protected RedisQueueFlowRepository $redis,
        protected DatabaseQueueFlowRepository $database,
    ) {
        //
    }

    /**
     * Get queue flow data from every enabled telemetry source.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        [$sources, $errors] = $this->collectSources();
        $queues = $this->queues($sources);
        $failed = max($queues->sum('failed'), $sources->sum(fn (array $source): int => (int) ($source['summary']['failed'] ?? 0)));
        $completed = $this->nullableSum($sources, 'completed');
        $throughput = $queues->sum('throughput_per_minute');
        $currentThroughput = $queues->sum('current_throughput_per_minute');

        return [
            'source' => 'auto',
            'sources' => $sources->pluck('source')->unique()->values()->all(),
            'errors' => $errors,
            'meta' => $this->metadata(),
            'generated_at' => Carbon::now()->toJSON(),
            'summary' => [
                'pending' => $queues->sum('pending'),
                'processing' => $this->nullableQueueSum($queues, 'processes'),
                'completed' => $completed,
                'failed' => $failed,
                'delayed' => $this->nullableQueueSum($queues, 'delayed'),
                'throughput_per_minute' => $throughput,
                'current_throughput_per_minute' => $currentThroughput,
                'average_wait_seconds' => (int) round($queues->avg('wait_seconds') ?? 0),
                'connections' => $queues->pluck('connection')->unique()->values()->all(),
            ],
            'nodes' => $this->nodes($queues->all(), $failed),
            'edges' => $this->edges($queues->all(), $failed, $currentThroughput),
            'queues' => $queues->values()->all(),
            'events' => $this->events($sources),
        ];
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<int, array<string, mixed>>, 1: array<int, array{source: string, message: string, exception: class-string<\Throwable>}>}
     */
    protected function collectSources(): array
    {
        $sources = collect();
        $errors = [];

        foreach ((array) config('horizonxbrain.flow.sources', ['redis']) as $source) {
            try {
                $payload = $this->source($source);
            } catch (Throwable $exception) {
                $errors[] = [
                    'source' => (string) $source,
                    'message' => $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class,
                    'exception' => $exception::class,
                ];

                continue;
            }

            if ($payload !== null) {
                $sources->push($payload);
            }
        }

        return [$sources->values(), $errors];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function source(string $source): ?array
    {
        return match ($source) {
            'redis' => $this->redis->get(),
            'database' => $this->database->get(),
            default => null,
        };
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $sources
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function queues(Collection $sources): Collection
    {
        return $sources
            ->flatMap(fn (array $source): array => $source['queues'] ?? [])
            ->map(fn (array $queue): array => $this->normalizeQueue($queue))
            ->groupBy(fn (array $queue): string => $this->queueKey($queue))
            ->map(fn (Collection $queues): array => $this->mergeQueueGroup($queues))
            ->sortBy(fn (array $queue): string => $this->queueKey($queue))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeQueue(array $queue): array
    {
        $pending = (int) ($queue['pending'] ?? 0);
        $throughput = (int) ($queue['throughput_per_minute'] ?? 0);
        $processes = ! array_key_exists('processes', $queue) || $queue['processes'] === null
            ? null
            : (int) $queue['processes'];
        $currentThroughput = (int) ($queue['current_throughput_per_minute'] ?? 0);

        return [
            'connection' => (string) ($queue['connection'] ?? $queue['driver'] ?? 'unknown'),
            'storage_connection' => $queue['storage_connection'] ?? null,
            'name' => (string) ($queue['name'] ?? 'default'),
            'pending' => $pending,
            'wait_seconds' => (int) ($queue['wait_seconds'] ?? 0),
            'oldest_pending_seconds' => (int) ($queue['oldest_pending_seconds'] ?? $queue['wait_seconds'] ?? 0),
            'processes' => $processes,
            'throughput_per_minute' => $throughput,
            'current_throughput_per_minute' => $currentThroughput,
            'recent_activity_per_minute' => (int) ($queue['recent_activity_per_minute'] ?? 0),
            'estimated_drain_seconds' => $queue['estimated_drain_seconds'] ?? $this->estimatedDrainSeconds($pending, $throughput),
            'attempts' => (int) ($queue['attempts'] ?? 0),
            'completed' => $queue['completed'] ?? null,
            'delayed' => $queue['delayed'] ?? null,
            'failed' => (int) ($queue['failed'] ?? 0),
            'failure_rate' => $queue['failure_rate'] ?? null,
            'latest_error' => $queue['latest_error'] ?? null,
            'last_failed_at' => $queue['last_failed_at'] ?? null,
            'jobs' => $queue['jobs'] ?? [],
            'job_classes' => $queue['job_classes'] ?? [],
            'driver' => (string) ($queue['driver'] ?? 'unknown'),
            'source' => (string) ($queue['source'] ?? $queue['driver'] ?? 'unknown'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mergeQueueGroup(Collection $queues): array
    {
        $first = $queues->first();

        $drivers = $queues->pluck('driver')->filter()->unique()->values()->all();
        $connections = $queues->pluck('connection')->filter()->unique()->values()->all();
        $sources = $queues->pluck('source')->filter()->unique()->values()->all();
        $totalFailed = (int) $queues->sum('failed');
        $totalCompleted = $this->nullableQueueSum($queues, 'completed');
        $totalPending = (int) $queues->sum('pending');
        $totalThroughput = $this->nullableQueueSum($queues, 'throughput_per_minute');

        return [
            ...$first,
            'name' => (string) ($first['name'] ?? 'default'),
            'connection' => $connections[0] ?? ($first['connection'] ?? 'unknown'),
            'driver' => $drivers[0] ?? ($first['driver'] ?? 'unknown'),
            'source' => $sources[0] ?? ($first['source'] ?? 'unknown'),
            'drivers' => $drivers,
            'connections' => $connections,
            'sources' => $sources,
            'pending' => $totalPending,
            'wait_seconds' => (int) $queues->max('wait_seconds'),
            'oldest_pending_seconds' => (int) $queues->max('oldest_pending_seconds'),
            'processes' => $this->nullableQueueSum($queues, 'processes'),
            'throughput_per_minute' => $totalThroughput,
            'current_throughput_per_minute' => (int) $queues->sum('current_throughput_per_minute'),
            'recent_activity_per_minute' => (int) $queues->sum('recent_activity_per_minute'),
            'estimated_drain_seconds' => $this->estimatedDrainSeconds($totalPending, (int) ($totalThroughput ?? 0)),
            'attempts' => (int) $queues->max('attempts'),
            'completed' => $totalCompleted,
            'delayed' => $this->nullableQueueSum($queues, 'delayed'),
            'failed' => $totalFailed,
            'failure_rate' => $this->failureRate($totalFailed, (int) ($totalCompleted ?? 0)),
            'latest_error' => $queues->pluck('latest_error')->filter()->first(),
            'last_failed_at' => $queues->pluck('last_failed_at')->filter()->sortDesc()->first(),
            'jobs' => $queues->pluck('jobs')->flatten(1)->sortByDesc(fn (array $job): int => (int) ($job['timestamp'] ?? 0))->take(12)->values()->all(),
            'job_classes' => $this->mergeJobClasses($queues->pluck('job_classes')->flatten(1)->all()),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $classes
     * @return array<int, array<string, mixed>>
     */
    protected function mergeJobClasses(array $classes): array
    {
        return collect($classes)
            ->groupBy(fn (array $class): string => (string) ($class['name'] ?? 'Queued job'))
            ->map(fn (Collection $classes, string $name): array => [
                'name' => $name,
                'pending' => $classes->sum('pending'),
                'reserved' => $classes->sum('reserved'),
                'completed' => $classes->sum('completed'),
                'failed' => $classes->sum('failed'),
                'attempts' => $classes->max('attempts') ?? 0,
                'latest_error' => $classes->pluck('latest_error')->filter()->first(),
            ])
            ->sortByDesc(fn (array $class): int => (int) $class['failed'] + (int) $class['pending'] + (int) $class['reserved'] + (int) $class['completed'])
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $queues
     * @return array<int, array<string, mixed>>
     */
    protected function nodes(array $queues, int $failed): array
    {
        $nodes = [
            $this->node('producer-app', 'producer', config('app.name', 'Application'), 'healthy', ['environment' => app()->environment()]),
            $this->node('workers', 'worker', 'Queue Workers', 'healthy', ['processes' => collect($queues)->sum('processes')]),
            $this->node('completed', 'result', 'completed', 'healthy'),
            $this->node('failed', 'result', 'failed', $failed > 0 ? 'critical' : 'healthy', ['failed' => $failed]),
        ];

        foreach ($queues as $queue) {
            $nodes[] = $this->node($this->queueId($queue), 'queue', $queue['name'], $this->status($queue), [
                'pending' => $queue['pending'],
                'wait' => $queue['wait_seconds'],
                'failed' => $queue['failed'],
                'throughput' => $queue['throughput_per_minute'],
                'current_throughput' => $queue['current_throughput_per_minute'],
                'latest_error' => $queue['latest_error'],
            ]);
        }

        return $nodes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $queues
     * @return array<int, array<string, mixed>>
     */
    protected function edges(array $queues, int $failed, int $currentThroughput): array
    {
        $edges = [];

        foreach ($queues as $queue) {
            $queueId = $this->queueId($queue);
            $status = $this->status($queue);
            $rate = (int) $queue['current_throughput_per_minute'];

            $edges[] = $this->edge('producer-app', $queueId, $status, 'dispatch', $rate);
            $edges[] = $this->edge($queueId, 'workers', $status, 'reserve', $rate);
        }

        $edges[] = $this->edge('workers', 'completed', 'healthy', 'finish', $currentThroughput);

        if ($failed > 0) {
            $edges[] = $this->edge('workers', 'failed', 'critical', "{$failed} failed", 0);
        }

        return $edges;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $sources
     * @return array<int, array<string, string>>
     */
    protected function events(Collection $sources): array
    {
        return $sources
            ->flatMap(fn (array $source): array => collect($source['events'] ?? [])
                ->map(fn (array $event): array => [
                    ...$event,
                    'status' => $event['status'] ?? 'healthy',
                    'label' => '['.($source['source'] ?? 'source').'] '.($event['label'] ?? 'Queue event'),
                    'source' => $source['source'] ?? null,
                ])
                ->all())
            ->sortByDesc(fn (array $event): int => (int) ($event['timestamp'] ?? 0))
            ->take(60)
            ->values()
            ->all();
    }

    protected function queueKey(array $queue): string
    {
        return strtolower((string) ($queue['name'] ?? 'default'));
    }

    protected function queueId(array $queue): string
    {
        return 'queue-'.preg_replace('/[^a-z0-9]+/i', '-', $this->queueKey($queue));
    }

    protected function status(array $queue): string
    {
        return match (true) {
            (int) $queue['failed'] > 0 => 'critical',
            (int) $queue['wait_seconds'] >= 30 || (int) $queue['pending'] >= 500 => 'critical',
            (int) $queue['wait_seconds'] >= 10 || (int) $queue['pending'] >= 100 || (int) ($queue['delayed'] ?? 0) > 0 => 'warning',
            default => 'healthy',
        };
    }

    protected function nullableSum(Collection $sources, string $key): ?int
    {
        $values = $sources
            ->pluck("summary.{$key}")
            ->filter(fn ($value): bool => $value !== null);

        return $values->isEmpty() ? null : (int) $values->sum();
    }

    protected function nullableQueueSum(Collection $queues, string $key): ?int
    {
        $values = $queues
            ->pluck($key)
            ->filter(fn ($value): bool => $value !== null);

        return $values->isEmpty() ? null : (int) $values->sum();
    }

    protected function estimatedDrainSeconds(int $pending, int $throughput): ?int
    {
        if ($pending <= 0 || $throughput <= 0) {
            return null;
        }

        return (int) ceil($pending / $throughput * 60);
    }

    protected function failureRate(int $failed, int $completed): ?float
    {
        $total = $failed + $completed;

        if ($total === 0) {
            return null;
        }

        return round($failed / $total * 100, 1);
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
    protected function edge(string $source, string $target, string $status, string $label, int $rate): array
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
