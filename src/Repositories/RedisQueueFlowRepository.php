<?php

namespace Laravel\Horizon\Repositories;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\QueueFlowRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Throwable;

class RedisQueueFlowRepository implements QueueFlowRepository
{
    use BuildsQueueFlowMetadata;

    /**
     * Create a new Redis queue flow repository.
     */
    public function __construct(
        protected WorkloadRepository $workload,
        protected JobRepository $jobs,
        protected MetricsRepository $metrics,
        protected SupervisorRepository $supervisors,
    ) {
        //
    }

    /**
     * Get queue flow data from Horizon's Redis telemetry.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $queues = $this->queues();
        $processes = $queues->sum('processes');
        $failed = $this->jobs->countFailed();

        return [
            'source' => 'redis',
            'meta' => $this->metadata(),
            'generated_at' => Carbon::now()->toJSON(),
            'summary' => [
                'pending' => $queues->sum('length'),
                'processing' => $processes,
                'completed' => $this->jobs->countCompleted(),
                'failed' => $failed,
                'delayed' => null,
                'throughput_per_minute' => $this->metrics->jobsProcessedPerMinute(),
                'current_throughput_per_minute' => $queues->sum('current_throughput'),
                'average_wait_seconds' => (int) round($queues->avg('wait') ?? 0),
                'connections' => ['redis'],
            ],
            'nodes' => $this->nodes($queues->all(), $failed),
            'edges' => $this->edges($queues->all(), $failed),
            'queues' => $queues->map(fn (array $queue): array => $this->queue($queue))->all(),
            'events' => $this->events(),
        ];
    }

    /**
     * Get queues from workload stats and Horizon's job indexes.
     *
     * Workload can be empty when jobs are visible in Horizon but no supervisor has
     * reported a queue length yet. The job indexes keep the graph useful in that
     * state and expose queue names like "orders-sync" immediately.
     *
     * @return \Illuminate\Support\Collection<int, array{name: string, length: int, wait: int, processes: int, failed: int, latest_error: string|null}>
     */
    protected function queues(): Collection
    {
        $queues = collect($this->workload->get())
            ->mapWithKeys(function (array $queue): array {
                $queue = $this->normalizeQueue($queue);

                return [$queue['key'] => $queue];
            })
            ->all();

        foreach ($this->queueObservations() as $name => $observation) {
            $queue = $queues[$name] ?? $this->emptyQueue($name);
            $queue['length'] = max((int) $queue['length'], $observation['pending']);
            $queue['wait'] = max((int) $queue['wait'], $observation['wait']);
            $queue['failed'] = max((int) $queue['failed'], $observation['failed']);
            $queue['completed'] = max((int) $queue['completed'], $observation['completed']);
            $queue['attempts'] = max((int) $queue['attempts'], $observation['attempts']);
            $queue['latest_error'] ??= $observation['latest_error'];
            $queue['current_throughput'] = $this->currentThroughput((int) $queue['processes'], (int) $queue['throughput']);

            $queues[$name] = $queue;
        }

        return collect($queues)
            ->sortBy(fn (array $queue): string => $this->connectionName($queue['name']).':'.$this->queueName($queue['name']))
            ->values();
    }

    /**
     * @param  array<int, array<string, mixed>>  $queues
     * @return array<int, array<string, mixed>>
     */
    protected function nodes(array $queues, int $failed): array
    {
        $nodes = [
            $this->node('producer-app', 'producer', config('app.name', 'Application'), 'healthy', [
                'environment' => app()->environment(),
                'throughput' => $queues === [] ? 0 : $this->metrics->jobsProcessedPerMinute(),
            ]),
            $this->node('workers', 'worker', 'Horizon Workers', 'healthy', ['processes' => collect($queues)->sum('processes')]),
            $this->node('completed', 'result', 'completed', 'healthy'),
            $this->node('failed', 'result', 'failed', $failed > 0 ? 'critical' : 'healthy', ['failed' => $failed]),
        ];

        foreach ($queues as $queue) {
            $nodes[] = $this->node(
                $this->queueId($queue['name']),
                'queue',
                $queue['name'],
                $this->status((int) $queue['wait'], (int) $queue['length'], (int) $queue['failed']),
                [
                    'pending' => (int) $queue['length'],
                    'wait' => (int) $queue['wait'],
                    'failed' => (int) $queue['failed'],
                    'latest_error' => $queue['latest_error'],
                    'current_throughput' => (int) $queue['current_throughput'],
                    'throughput' => (int) $queue['throughput'],
                ]
            );
        }

        return $nodes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $queues
     * @return array<int, array<string, mixed>>
     */
    protected function edges(array $queues, int $failed): array
    {
        $edges = [];

        foreach ($queues as $queue) {
            $status = $this->status((int) $queue['wait'], (int) $queue['length'], (int) $queue['failed']);
            $queueId = $this->queueId($queue['name']);
            $throughput = (int) $queue['current_throughput'];

            $edges[] = $this->edge('producer-app', $queueId, $status, 'dispatch', $throughput);
            $edges[] = $this->edge($queueId, 'workers', $status, 'reserve', $throughput);
        }

        $edges[] = $this->edge('workers', 'completed', 'healthy', 'finish', collect($queues)->sum('current_throughput'));

        if ($failed > 0) {
            $edges[] = $this->edge('workers', 'failed', 'critical', "{$failed} failed", 0);
        }

        return $edges;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function events(): array
    {
        $jobs = collect();

        foreach ([
            'critical' => fn () => $this->jobs->getFailed(-1),
            'warning' => fn () => $this->jobs->getPending(-1),
            'healthy' => fn () => $this->jobs->getCompleted(-1),
        ] as $status => $resolver) {
            try {
                $jobs = $jobs->merge($resolver()->take(4)->map(fn (object $job): array => [
                    'status' => $status,
                    'label' => sprintf('%s on %s:%s is %s',
                        $job->name ?? 'Queued job',
                        $job->connection ?? $this->connectionName($job->queue ?? 'default'),
                        $job->queue ?? 'default',
                        $job->status ?? $status
                    ),
                ]));
            } catch (\Throwable) {
                //
            }
        }

        if ($jobs->isNotEmpty()) {
            return $jobs->take(8)->values()->all();
        }

        return collect($this->supervisors->all())->take(3)->map(fn (array $supervisor): array => [
            'status' => $supervisor['status'] === 'paused' ? 'warning' : 'healthy',
            'label' => sprintf('%s supervisor is %s', $supervisor['name'] ?? 'Horizon', $supervisor['status'] ?? 'running'),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function queue(array $queue): array
    {
        return [
            'connection' => 'redis',
            'storage_connection' => $queue['storage_connection'],
            'name' => $queue['name'],
            'pending' => (int) $queue['length'],
            'wait_seconds' => (int) $queue['wait'],
            'processes' => (int) $queue['processes'],
            'throughput_per_minute' => (int) $queue['throughput'],
            'current_throughput_per_minute' => (int) $queue['current_throughput'],
            'oldest_pending_seconds' => (int) $queue['wait'],
            'estimated_drain_seconds' => $this->estimatedDrainSeconds((int) $queue['length'], (int) $queue['current_throughput']),
            'attempts' => (int) $queue['attempts'],
            'completed' => (int) $queue['completed'],
            'failed' => (int) $queue['failed'],
            'failure_rate' => $this->failureRate((int) $queue['failed'], (int) $queue['completed']),
            'latest_error' => $queue['latest_error'],
            'driver' => 'redis',
            'source' => 'redis',
        ];
    }

    /**
     * @return array<string, array{pending: int, wait: int, failed: int, completed: int, attempts: int, latest_error: string|null}>
     */
    protected function queueObservations(): array
    {
        $observations = [];

        foreach ([
            'pending' => fn (): Collection => $this->jobs->getPending(-1),
            'completed' => fn (): Collection => $this->jobs->getCompleted(-1),
            'failed' => fn (): Collection => $this->jobs->getFailed(-1),
        ] as $status => $resolver) {
            try {
                foreach ($resolver() as $job) {
                    $name = $this->jobQueueKey($job);

                    if ($name === null) {
                        continue;
                    }

                    $observations[$name] ??= ['pending' => 0, 'wait' => 0, 'failed' => 0, 'completed' => 0, 'attempts' => 0, 'latest_error' => null];

                    if ($status === 'pending') {
                        $observations[$name]['pending']++;
                        $observations[$name]['wait'] = max(
                            $observations[$name]['wait'],
                            $this->jobWaitSeconds($job)
                        );
                        $observations[$name]['attempts'] = max(
                            $observations[$name]['attempts'],
                            $this->jobAttempts($job)
                        );
                    }

                    if ($status === 'completed') {
                        $observations[$name]['completed']++;
                    }

                    if ($status === 'failed') {
                        $observations[$name]['failed']++;
                        $observations[$name]['attempts'] = max(
                            $observations[$name]['attempts'],
                            $this->jobAttempts($job)
                        );
                        $observations[$name]['latest_error'] ??= $this->jobExceptionSummary($job);
                    }
                }
            } catch (Throwable) {
                //
            }
        }

        return $observations;
    }

    /**
     * @return array{key: string, storage_connection: string, name: string, length: int, wait: int, processes: int, throughput: int, current_throughput: int, completed: int, failed: int, attempts: int, latest_error: string|null}
     */
    protected function normalizeQueue(array $queue): array
    {
        $rawName = (string) ($queue['name'] ?? 'default');
        $name = $this->queueName($rawName);
        $throughput = $this->throughputForQueue($name);
        $length = (int) ($queue['length'] ?? 0);
        $processes = (int) ($queue['processes'] ?? 0);

        return [
            'key' => $this->redisQueueKey($name),
            'storage_connection' => $this->connectionName($rawName),
            'name' => $name,
            'length' => $length,
            'wait' => (int) ($queue['wait'] ?? 0),
            'processes' => $processes,
            'throughput' => $throughput,
            'current_throughput' => $this->currentThroughput($processes, $throughput),
            'completed' => (int) ($queue['completed'] ?? 0),
            'failed' => (int) ($queue['failed'] ?? 0),
            'attempts' => (int) ($queue['attempts'] ?? 0),
            'latest_error' => $queue['latest_error'] ?? null,
        ];
    }

    /**
     * @return array{key: string, storage_connection: string, name: string, length: int, wait: int, processes: int, throughput: int, current_throughput: int, completed: int, failed: int, attempts: int, latest_error: string|null}
     */
    protected function emptyQueue(string $name): array
    {
        $queue = $this->queueName($name);
        $throughput = $this->throughputForQueue($queue);

        return [
            'key' => $this->redisQueueKey($queue),
            'storage_connection' => $this->connectionName($name),
            'name' => $queue,
            'length' => 0,
            'wait' => 0,
            'processes' => 0,
            'throughput' => $throughput,
            'current_throughput' => 0,
            'completed' => 0,
            'failed' => 0,
            'attempts' => 0,
            'latest_error' => null,
        ];
    }

    protected function jobQueueKey(object $job): ?string
    {
        $queue = trim((string) ($job->queue ?? ''));

        if ($queue === '') {
            return null;
        }

        return $this->redisQueueKey($this->queueName($queue));
    }

    protected function jobWaitSeconds(object $job): int
    {
        $createdAt = (float) ($job->created_at ?? 0);

        if ($createdAt <= 0 && is_string($job->payload ?? null)) {
            $payload = json_decode($job->payload);
            $createdAt = (float) ($payload->pushedAt ?? 0);
        }

        if ($createdAt <= 0) {
            return 0;
        }

        return max(0, Carbon::now()->timestamp - (int) floor($createdAt));
    }

    protected function jobExceptionSummary(object $job): ?string
    {
        $exception = trim((string) ($job->exception ?? ''));

        if ($exception === '') {
            return null;
        }

        $line = strtok($exception, "\n") ?: $exception;

        return Str::limit($line, 180);
    }

    protected function jobAttempts(object $job): int
    {
        if (! is_string($job->payload ?? null)) {
            return 0;
        }

        $payload = json_decode($job->payload);

        return (int) ($payload->attempts ?? 0);
    }

    protected function throughputForQueue(string $name): int
    {
        return max(
            $this->metrics->throughputForQueue($name),
            $this->metrics->throughputForQueue($this->queueName($name))
        );
    }

    protected function redisQueueKey(string $name): string
    {
        return 'redis:'.$this->queueName($name);
    }

    protected function currentThroughput(int $processes, int $throughput): int
    {
        return $processes > 0 ? $throughput : 0;
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

    protected function connectionName(string $name): string
    {
        if (str_contains($name, ':')) {
            return explode(':', $name, 2)[0];
        }

        return app()->bound('config') ? config('horizon.use', 'default') : 'default';
    }

    protected function queueName(string $name): string
    {
        return str_contains($name, ':') ? explode(':', $name, 2)[1] : $name;
    }

    protected function queueId(string $name): string
    {
        return 'queue-'.preg_replace('/[^a-z0-9]+/i', '-', strtolower($name));
    }

    protected function status(int $wait, int $length, int $failed = 0): string
    {
        return match (true) {
            $failed > 0 || $wait >= 30 || $length >= 500 => 'critical',
            $wait >= 10 || $length >= 100 => 'warning',
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
