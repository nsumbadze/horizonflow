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
                'average_wait_seconds' => (int) round($queues->avg('wait') ?? 0),
                'connections' => $queues->map(fn (array $queue): string => $this->connectionName($queue['name']))->unique()->values()->all(),
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

                return [$queue['name'] => $queue];
            })
            ->all();

        foreach ($this->queueObservations() as $name => $observation) {
            $queue = $queues[$name] ?? $this->emptyQueue($name);
            $queue['length'] = max((int) $queue['length'], $observation['pending']);
            $queue['wait'] = max((int) $queue['wait'], $observation['wait']);
            $queue['failed'] = max((int) $queue['failed'], $observation['failed']);
            $queue['latest_error'] ??= $observation['latest_error'];

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
                'throughput' => $this->metrics->jobsProcessedPerMinute(),
            ]),
            $this->node('workers', 'worker', 'Horizon Workers', 'healthy', ['processes' => collect($queues)->sum('processes')]),
            $this->node('completed', 'result', 'completed', 'healthy'),
            $this->node('failed', 'result', 'failed', $failed > 0 ? 'critical' : 'healthy', ['failed' => $failed]),
        ];

        foreach ($queues as $queue) {
            $nodes[] = $this->node(
                $this->queueId($queue['name']),
                'queue',
                $this->queueName($queue['name']),
                $this->status((int) $queue['wait'], (int) $queue['length'], (int) $queue['failed']),
                [
                    'pending' => (int) $queue['length'],
                    'wait' => (int) $queue['wait'],
                    'failed' => (int) $queue['failed'],
                    'latest_error' => $queue['latest_error'],
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
            $throughput = $this->throughputForQueue($queue['name']);

            $edges[] = $this->edge('producer-app', $queueId, $status, 'dispatch', $throughput);
            $edges[] = $this->edge($queueId, 'workers', $status, 'reserve', $throughput);
        }

        $edges[] = $this->edge('workers', 'completed', 'healthy', 'finish', $this->metrics->jobsProcessedPerMinute());

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
            'connection' => $this->connectionName($queue['name']),
            'name' => $this->queueName($queue['name']),
            'pending' => (int) $queue['length'],
            'wait_seconds' => (int) $queue['wait'],
            'processes' => (int) $queue['processes'],
            'throughput_per_minute' => $this->throughputForQueue($queue['name']),
            'failed' => (int) $queue['failed'],
            'latest_error' => $queue['latest_error'],
            'driver' => 'redis',
        ];
    }

    /**
     * @return array<string, array{pending: int, wait: int, failed: int, latest_error: string|null}>
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

                    $observations[$name] ??= ['pending' => 0, 'wait' => 0, 'failed' => 0, 'latest_error' => null];

                    if ($status === 'pending') {
                        $observations[$name]['pending']++;
                        $observations[$name]['wait'] = max(
                            $observations[$name]['wait'],
                            $this->jobWaitSeconds($job)
                        );
                    }

                    if ($status === 'failed') {
                        $observations[$name]['failed']++;
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
     * @return array{name: string, length: int, wait: int, processes: int, failed: int, latest_error: string|null}
     */
    protected function normalizeQueue(array $queue): array
    {
        return [
            'name' => (string) ($queue['name'] ?? 'default'),
            'length' => (int) ($queue['length'] ?? 0),
            'wait' => (int) ($queue['wait'] ?? 0),
            'processes' => (int) ($queue['processes'] ?? 0),
            'failed' => (int) ($queue['failed'] ?? 0),
            'latest_error' => $queue['latest_error'] ?? null,
        ];
    }

    /**
     * @return array{name: string, length: int, wait: int, processes: int, failed: int, latest_error: string|null}
     */
    protected function emptyQueue(string $name): array
    {
        return [
            'name' => $name,
            'length' => 0,
            'wait' => 0,
            'processes' => 0,
            'failed' => 0,
            'latest_error' => null,
        ];
    }

    protected function jobQueueKey(object $job): ?string
    {
        $queue = trim((string) ($job->queue ?? ''));

        if ($queue === '') {
            return null;
        }

        if (str_contains($queue, ':')) {
            return $queue;
        }

        $connection = trim((string) ($job->connection ?? $this->connectionName($queue)));

        return $connection === '' ? $queue : "{$connection}:{$queue}";
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

    protected function throughputForQueue(string $name): int
    {
        return max(
            $this->metrics->throughputForQueue($name),
            $this->metrics->throughputForQueue($this->queueName($name))
        );
    }

    protected function connectionName(string $name): string
    {
        return str_contains($name, ':') ? explode(':', $name, 2)[0] : config('horizon.use', 'redis');
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
