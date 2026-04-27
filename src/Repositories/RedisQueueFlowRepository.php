<?php

namespace Laravel\Horizon\Repositories;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
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
        protected ?RedisFactory $redis = null,
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

        foreach ($this->configuredRedisQueues() as $name) {
            $queues[$name] ??= $this->emptyQueue($name);
        }

        foreach ($this->measuredRedisQueues() as $name) {
            $queues[$name] ??= $this->emptyQueue($name);
        }

        foreach ($this->discoveredRedisQueues() as $name => $discovered) {
            $queue = $queues[$name] ?? $this->emptyQueue($name);
            $queue['length'] = max((int) $queue['length'], $discovered['length']);
            $queue['delayed'] = max((int) $queue['delayed'], $discovered['delayed']);
            $queue['reserved'] = max((int) $queue['reserved'], $discovered['reserved']);

            $queues[$name] = $queue;
        }

        foreach ($this->queueObservations() as $name => $observation) {
            $queue = $queues[$name] ?? $this->emptyQueue($name);
            $queue['length'] = max((int) $queue['length'], $observation['pending']);
            $queue['wait'] = max((int) $queue['wait'], $observation['wait']);
            $queue['failed'] = max((int) $queue['failed'], $observation['failed']);
            $queue['completed'] = max((int) $queue['completed'], $observation['completed']);
            $queue['attempts'] = max((int) $queue['attempts'], $observation['attempts']);
            $queue['recent_activity'] = max((int) $queue['recent_activity'], $observation['recent_activity']);
            $queue['latest_error'] ??= $observation['latest_error'];
            $queue['jobs'] = $this->mergeRecentJobs($queue['jobs'], $observation['jobs']);
            $queue['job_classes'] = $this->mergeJobClasses($queue['job_classes'], $observation['job_classes']);

            $queues[$name] = $queue;
        }

        return collect($queues)
            ->map(function (array $queue): array {
                $queue['current_throughput'] = $this->currentThroughput(
                    (int) $queue['processes'],
                    (int) $queue['reserved'],
                    (int) $queue['throughput']
                );
                $queue['current_throughput'] = max(
                    (int) $queue['current_throughput'],
                    (int) $queue['recent_activity']
                );

                return $queue;
            })
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
                $this->status((int) $queue['wait'], (int) $queue['length'], (int) $queue['failed'], (int) $queue['delayed']),
                [
                    'pending' => (int) $queue['length'],
                    'wait' => (int) $queue['wait'],
                    'delayed' => (int) $queue['delayed'],
                    'reserved' => (int) $queue['reserved'],
                    'failed' => (int) $queue['failed'],
                    'latest_error' => $queue['latest_error'],
                    'current_throughput' => (int) $queue['current_throughput'],
                    'recent_activity' => (int) $queue['recent_activity'],
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
            $status = $this->status((int) $queue['wait'], (int) $queue['length'], (int) $queue['failed'], (int) $queue['delayed']);
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
                $jobs = $jobs->merge($resolver()->take(15)->map(fn (object $job): array => $this->event($job, $status)));
            } catch (\Throwable) {
                //
            }
        }

        if ($jobs->isNotEmpty()) {
            return $jobs
                ->filter(fn (array $event): bool => $this->isRecentEvent($event))
                ->sortByDesc(fn (array $event): int => (int) ($event['timestamp'] ?? 0))
                ->take(30)
                ->values()
                ->all();
        }

        return collect($this->supervisors->all())->take(3)->map(fn (array $supervisor): array => [
            'status' => $supervisor['status'] === 'paused' ? 'warning' : 'healthy',
            'label' => sprintf('%s supervisor is %s', $supervisor['name'] ?? 'Horizon', $supervisor['status'] ?? 'running'),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function isRecentEvent(array $event): bool
    {
        $timestamp = (int) ($event['timestamp'] ?? 0);

        return $timestamp > 0 && Carbon::now()->timestamp - $timestamp <= 30;
    }

    /**
     * @return array<string, mixed>
     */
    protected function event(object $job, string $status): array
    {
        $state = (string) ($job->status ?? $status);
        $queue = (string) ($job->queue ?? 'default');

        return [
            'id' => (string) ($job->id ?? sha1((string) ($job->name ?? 'Queued job').(string) ($job->payload ?? '').(string) ($job->created_at ?? ''))),
            'status' => $status,
            'state' => $state,
            'connection' => (string) ($job->connection ?? 'redis'),
            'queue' => $queue,
            'job' => (string) ($job->name ?? 'Queued job'),
            'timestamp' => $this->jobActivityTimestamp($job),
            'result' => match ($state) {
                'completed' => 'completed',
                'failed' => 'failed',
                'reserved' => 'workers',
                default => 'queue',
            },
            'label' => sprintf('%s on %s:%s is %s',
                $job->name ?? 'Queued job',
                $job->connection ?? $this->connectionName($queue),
                $queue,
                $state
            ),
        ];
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
            'reserved' => (int) $queue['reserved'],
            'throughput_per_minute' => (int) $queue['throughput'],
            'current_throughput_per_minute' => (int) $queue['current_throughput'],
            'recent_activity_per_minute' => (int) $queue['recent_activity'],
            'oldest_pending_seconds' => (int) $queue['wait'],
            'estimated_drain_seconds' => $this->estimatedDrainSeconds((int) $queue['length'], (int) $queue['current_throughput']),
            'attempts' => (int) $queue['attempts'],
            'completed' => (int) $queue['completed'],
            'delayed' => (int) $queue['delayed'],
            'failed' => (int) $queue['failed'],
            'failure_rate' => $this->failureRate((int) $queue['failed'], (int) $queue['completed']),
            'latest_error' => $queue['latest_error'],
            'jobs' => array_values($queue['jobs']),
            'job_classes' => array_values($queue['job_classes']),
            'driver' => 'redis',
            'source' => 'redis',
        ];
    }

    /**
     * @return array<string, array{length: int, delayed: int, reserved: int}>
     */
    protected function discoveredRedisQueues(): array
    {
        $connection = $this->redisConnection();

        if ($connection === null) {
            return [];
        }

        $queues = [];

        foreach ($this->redisQueueKeys($connection) as $key) {
            $queue = $this->queueNameFromRedisKey($key);

            if ($queue === null) {
                continue;
            }

            $queues[$this->redisQueueKey($queue)] = $this->redisQueueSnapshot($connection, $queue);
        }

        return $queues;
    }

    /**
     * @return array<int, string>
     */
    protected function configuredRedisQueues(): array
    {
        if (! app()->bound('config')) {
            return [];
        }

        $queues = collect();
        $redisQueue = config('queue.connections.redis.queue');

        if (is_string($redisQueue) && $redisQueue !== '') {
            $queues->push($redisQueue);
        }

        $supervisors = collect(config('horizon.defaults', []))
            ->merge(collect(config('horizon.environments.'.app()->environment(), [])));

        foreach ($supervisors as $supervisor) {
            if (($supervisor['connection'] ?? null) !== 'redis') {
                continue;
            }

            foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                if (is_string($queue) && $queue !== '') {
                    $queues->push($queue);
                }
            }
        }

        return $queues
            ->flatMap(fn (string $queue): array => array_map('trim', explode(',', $queue)))
            ->filter()
            ->unique()
            ->map(fn (string $queue): string => $this->redisQueueKey($queue))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function measuredRedisQueues(): array
    {
        try {
            return collect($this->metrics->measuredQueues())
                ->filter(fn (string $queue): bool => $queue !== '')
                ->map(fn (string $queue): string => $this->redisQueueKey($queue))
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    protected function redisConnection(): mixed
    {
        try {
            $redis = $this->redis ?? (app()->bound('redis') ? app('redis') : null);

            if ($redis === null) {
                return null;
            }

            $connection = config('queue.connections.redis.connection', config('horizon.use', 'default'));

            return $redis->connection($connection);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    protected function redisQueueKeys(mixed $connection): array
    {
        try {
            $keys = [];
            $cursor = null;

            do {
                $scan = $connection->scan($cursor ?? 0, ['match' => 'queues:*', 'count' => 100]);

                if (! is_array($scan)) {
                    break;
                }

                [$cursor, $results] = $scan;
                $keys = [...$keys, ...(array) $results];
            } while ((int) $cursor > 0);

            if ($keys !== []) {
                return $keys;
            }
        } catch (Throwable) {
            //
        }

        try {
            return (array) $connection->keys('queues:*');
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{length: int, delayed: int, reserved: int}
     */
    protected function redisQueueSnapshot(mixed $connection, string $queue): array
    {
        try {
            return [
                'length' => (int) $connection->llen("queues:{$queue}"),
                'delayed' => (int) $connection->zcard("queues:{$queue}:delayed"),
                'reserved' => (int) $connection->zcard("queues:{$queue}:reserved"),
            ];
        } catch (Throwable) {
            return ['length' => 0, 'delayed' => 0, 'reserved' => 0];
        }
    }

    protected function queueNameFromRedisKey(string $key): ?string
    {
        if (! preg_match('/queues:([^:]+)(?::(?:delayed|reserved))?$/', $key, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @return array<string, array{pending: int, wait: int, failed: int, completed: int, attempts: int, recent_activity: int, latest_error: string|null, jobs: array<string, array<string, mixed>>, job_classes: array<string, array<string, mixed>>}>
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

                    $observations[$name] ??= $this->emptyObservation();
                    $observations[$name] = $this->observeJob($observations[$name], $job, $status);

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

                        if (($job->status ?? null) === 'reserved' || $this->isRecentJobActivity($job)) {
                            $observations[$name]['recent_activity']++;
                        }
                    }

                    if ($status === 'completed') {
                        $observations[$name]['completed']++;

                        if ($this->isRecentJobActivity($job)) {
                            $observations[$name]['recent_activity']++;
                        }
                    }

                    if ($status === 'failed') {
                        $observations[$name]['failed']++;
                        $observations[$name]['attempts'] = max(
                            $observations[$name]['attempts'],
                            $this->jobAttempts($job)
                        );
                        $observations[$name]['latest_error'] ??= $this->jobExceptionSummary($job);

                        if ($this->isRecentJobActivity($job)) {
                            $observations[$name]['recent_activity']++;
                        }
                    }
                }
            } catch (Throwable) {
                //
            }
        }

        return $observations;
    }

    /**
     * @return array{key: string, storage_connection: string, name: string, length: int, wait: int, processes: int, delayed: int, reserved: int, throughput: int, current_throughput: int, recent_activity: int, completed: int, failed: int, attempts: int, latest_error: string|null, jobs: array<int, array<string, mixed>>, job_classes: array<int, array<string, mixed>>}
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
            'delayed' => (int) ($queue['delayed'] ?? 0),
            'reserved' => (int) ($queue['reserved'] ?? 0),
            'throughput' => $throughput,
            'current_throughput' => $this->currentThroughput($processes, (int) ($queue['reserved'] ?? 0), $throughput),
            'recent_activity' => 0,
            'completed' => (int) ($queue['completed'] ?? 0),
            'failed' => (int) ($queue['failed'] ?? 0),
            'attempts' => (int) ($queue['attempts'] ?? 0),
            'latest_error' => $queue['latest_error'] ?? null,
            'jobs' => $queue['jobs'] ?? [],
            'job_classes' => $queue['job_classes'] ?? [],
        ];
    }

    /**
     * @return array{key: string, storage_connection: string, name: string, length: int, wait: int, processes: int, delayed: int, reserved: int, throughput: int, current_throughput: int, recent_activity: int, completed: int, failed: int, attempts: int, latest_error: string|null, jobs: array<int, array<string, mixed>>, job_classes: array<int, array<string, mixed>>}
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
            'delayed' => 0,
            'reserved' => 0,
            'throughput' => $throughput,
            'current_throughput' => 0,
            'recent_activity' => 0,
            'completed' => 0,
            'failed' => 0,
            'attempts' => 0,
            'latest_error' => null,
            'jobs' => [],
            'job_classes' => [],
        ];
    }

    /**
     * @return array{pending: int, wait: int, failed: int, completed: int, attempts: int, recent_activity: int, latest_error: string|null, jobs: array<string, array<string, mixed>>, job_classes: array<string, array<string, mixed>>}
     */
    protected function emptyObservation(): array
    {
        return [
            'pending' => 0,
            'wait' => 0,
            'failed' => 0,
            'completed' => 0,
            'attempts' => 0,
            'recent_activity' => 0,
            'latest_error' => null,
            'jobs' => [],
            'job_classes' => [],
        ];
    }

    /**
     * @param  array{pending: int, wait: int, failed: int, completed: int, attempts: int, recent_activity: int, latest_error: string|null, jobs: array<string, array<string, mixed>>, job_classes: array<string, array<string, mixed>>}  $observation
     * @return array{pending: int, wait: int, failed: int, completed: int, attempts: int, recent_activity: int, latest_error: string|null, jobs: array<string, array<string, mixed>>, job_classes: array<string, array<string, mixed>>}
     */
    protected function observeJob(array $observation, object $job, string $bucket): array
    {
        $summary = $this->jobSummary($job, $bucket);
        $observation['jobs'][(string) $summary['id']] = $summary;

        $class = (string) $summary['name'];
        $observation['job_classes'][$class] ??= [
            'name' => $class,
            'pending' => 0,
            'reserved' => 0,
            'completed' => 0,
            'failed' => 0,
            'attempts' => 0,
            'latest_error' => null,
        ];

        $state = (string) $summary['status'];
        $state = in_array($state, ['reserved', 'completed', 'failed'], true) ? $state : 'pending';
        $observation['job_classes'][$class][$state]++;
        $observation['job_classes'][$class]['attempts'] = max(
            (int) $observation['job_classes'][$class]['attempts'],
            (int) $summary['attempts']
        );

        if ($summary['exception'] !== null) {
            $observation['job_classes'][$class]['latest_error'] ??= $summary['exception'];
        }

        return $observation;
    }

    /**
     * @return array<string, mixed>
     */
    protected function jobSummary(object $job, string $bucket): array
    {
        $status = (string) ($job->status ?? $bucket);
        $status = match ($status) {
            'completed', 'failed', 'reserved' => $status,
            default => $bucket === 'completed' || $bucket === 'failed' ? $bucket : 'pending',
        };

        $id = (string) ($job->id ?? sha1(implode('|', [
            (string) ($job->connection ?? 'redis'),
            (string) ($job->queue ?? 'default'),
            (string) ($job->name ?? 'Queued job'),
            (string) ($job->payload ?? ''),
            (string) ($job->created_at ?? ''),
        ])));

        return [
            'id' => $id,
            'name' => (string) ($job->name ?? 'Queued job'),
            'status' => $status,
            'connection' => (string) ($job->connection ?? 'redis'),
            'queue' => (string) ($job->queue ?? 'default'),
            'attempts' => $this->jobAttempts($job),
            'runtime_seconds' => $this->jobRuntimeSeconds($job),
            'age_seconds' => $this->jobWaitSeconds($job),
            'timestamp' => $this->jobActivityTimestamp($job),
            'exception' => $this->jobExceptionSummary($job),
            'retryable' => $status === 'failed',
        ];
    }

    protected function jobRuntimeSeconds(object $job): ?int
    {
        $reservedAt = (float) ($job->reserved_at ?? 0);
        $finishedAt = (float) ($job->completed_at ?? $job->failed_at ?? 0);

        if ($reservedAt <= 0 || $finishedAt <= 0 || $finishedAt < $reservedAt) {
            return null;
        }

        return (int) ceil($finishedAt - $reservedAt);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $existing
     * @param  array<int|string, array<string, mixed>>  $incoming
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
     * @param  array<int|string, array<string, mixed>>  $existing
     * @param  array<int|string, array<string, mixed>>  $incoming
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

    protected function isRecentJobActivity(object $job): bool
    {
        $timestamp = $this->jobActivityTimestamp($job);

        return $timestamp !== null && Carbon::now()->timestamp - $timestamp <= 60;
    }

    protected function jobActivityTimestamp(object $job): ?int
    {
        foreach (['completed_at', 'failed_at', 'updated_at', 'reserved_at', 'created_at'] as $field) {
            $timestamp = (float) ($job->{$field} ?? 0);

            if ($timestamp > 0) {
                return (int) floor($timestamp);
            }
        }

        if (is_string($job->payload ?? null)) {
            $payload = json_decode($job->payload);
            $timestamp = (float) ($payload->pushedAt ?? 0);

            if ($timestamp > 0) {
                return (int) floor($timestamp);
            }
        }

        return null;
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

    protected function currentThroughput(int $processes, int $reserved, int $throughput): int
    {
        return $processes > 0 || $reserved > 0 ? max($throughput, 1) : 0;
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

    protected function status(int $wait, int $length, int $failed = 0, int $delayed = 0): string
    {
        return match (true) {
            $failed > 0 || $wait >= 30 || $length >= 500 => 'critical',
            $wait >= 10 || $length >= 100 || $delayed > 0 => 'warning',
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
