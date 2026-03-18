<?php

namespace Laravel\Horizon\Repositories;

use Illuminate\Support\Carbon;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\QueueFlowRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

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
        $queues = collect($this->workload->get())->sortBy('name')->values();
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
                $this->status((int) $queue['wait'], (int) $queue['length']),
                ['pending' => (int) $queue['length'], 'wait' => (int) $queue['wait']]
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
            $status = $this->status((int) $queue['wait'], (int) $queue['length']);
            $queueId = $this->queueId($queue['name']);
            $throughput = $this->metrics->throughputForQueue($queue['name']);

            $edges[] = $this->edge('producer-app', $queueId, $status, 'dispatch', $throughput);
            $edges[] = $this->edge($queueId, 'workers', $status, 'reserve', $throughput);
        }

        $edges[] = $this->edge('workers', 'completed', 'healthy', 'finish', $this->metrics->jobsProcessedPerMinute());

        if ($failed > 0) {
            $edges[] = $this->edge('workers', 'failed', 'critical', 'exception', min($failed, 60));
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
            'throughput_per_minute' => $this->metrics->throughputForQueue($queue['name']),
            'driver' => 'redis',
        ];
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

    protected function status(int $wait, int $length): string
    {
        return match (true) {
            $wait >= 30 || $length >= 500 => 'critical',
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
