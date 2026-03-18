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
        $sources = $this->sources();
        $queues = $this->queues($sources);
        $failed = max($queues->sum('failed'), $sources->sum(fn (array $source): int => (int) ($source['summary']['failed'] ?? 0)));
        $completed = $this->nullableSum($sources, 'completed');
        $throughput = $queues->sum('throughput_per_minute');
        $currentThroughput = $queues->sum('current_throughput_per_minute');

        return [
            'source' => 'auto',
            'sources' => $sources->pluck('source')->unique()->values()->all(),
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
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function sources(): Collection
    {
        return collect(config('horizonxbrain.flow.sources', ['redis', 'database']))
            ->map(fn (string $source): ?array => $this->source($source))
            ->filter()
            ->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function source(string $source): ?array
    {
        try {
            return match ($source) {
                'redis' => $this->redis->get(),
                'database' => $this->database->get(),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
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
            'estimated_drain_seconds' => $queue['estimated_drain_seconds'] ?? $this->estimatedDrainSeconds($pending, $throughput),
            'attempts' => (int) ($queue['attempts'] ?? 0),
            'completed' => $queue['completed'] ?? null,
            'delayed' => $queue['delayed'] ?? null,
            'failed' => (int) ($queue['failed'] ?? 0),
            'failure_rate' => $queue['failure_rate'] ?? null,
            'latest_error' => $queue['latest_error'] ?? null,
            'last_failed_at' => $queue['last_failed_at'] ?? null,
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

        return [
            ...$first,
            'pending' => $queues->sum('pending'),
            'wait_seconds' => $queues->max('wait_seconds'),
            'oldest_pending_seconds' => $queues->max('oldest_pending_seconds'),
            'processes' => $this->nullableQueueSum($queues, 'processes'),
            'throughput_per_minute' => $queues->sum('throughput_per_minute'),
            'current_throughput_per_minute' => $queues->sum('current_throughput_per_minute'),
            'estimated_drain_seconds' => $this->estimatedDrainSeconds($queues->sum('pending'), $queues->sum('throughput_per_minute')),
            'attempts' => $queues->max('attempts'),
            'completed' => $this->nullableQueueSum($queues, 'completed'),
            'delayed' => $this->nullableQueueSum($queues, 'delayed'),
            'failed' => $queues->sum('failed'),
            'failure_rate' => $this->failureRate($queues->sum('failed'), (int) $queues->sum('completed')),
            'latest_error' => $queues->pluck('latest_error')->filter()->first(),
            'last_failed_at' => $queues->pluck('last_failed_at')->filter()->sortDesc()->first(),
        ];
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
                    'status' => $event['status'] ?? 'healthy',
                    'label' => '['.($source['source'] ?? 'source').'] '.($event['label'] ?? 'Queue event'),
                ])
                ->all())
            ->take(10)
            ->values()
            ->all();
    }

    protected function queueKey(array $queue): string
    {
        return implode(':', [$queue['driver'], $queue['connection'], $queue['name']]);
    }

    protected function queueId(array $queue): string
    {
        return 'queue-'.preg_replace('/[^a-z0-9]+/i', '-', strtolower($this->queueKey($queue)));
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
