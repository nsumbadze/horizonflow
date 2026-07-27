<?php

namespace Laravel\Horizon\Repositories;

use Illuminate\Support\Carbon;
use Laravel\Horizon\Contracts\QueueFlowRepository;
use Throwable;

class MockQueueFlowRepository implements QueueFlowRepository
{
    use BuildsQueueFlowMetadata;

    /**
     * Demo queues rendered on the live flow screen.
     *
     * The set intentionally covers every visual state the UI can render: a
     * healthy high-throughput queue, a backed up queue with delayed jobs, a
     * failing queue with an exception, and an idle queue.
     *
     * @var array<int, array<string, mixed>>
     */
    protected const QUEUES = [
        [
            'name' => 'default',
            'pending' => 46,
            'wait' => 3,
            'processes' => 12,
            'reserved' => 9,
            'delayed' => 0,
            'throughput' => 318,
            'completed' => 184320,
            'failed' => 0,
            'failed_in_window' => 0,
            'latest_error' => null,
            'classes' => [
                ['name' => 'Acme\\Jobs\\PolishWidget', 'pending' => 18, 'reserved' => 4, 'completed' => 63, 'failed' => 0],
                ['name' => 'Acme\\Jobs\\CalibrateGizmo', 'pending' => 21, 'reserved' => 3, 'completed' => 41, 'failed' => 0],
                ['name' => 'Acme\\Jobs\\SweepWorkshop', 'pending' => 7, 'reserved' => 2, 'completed' => 18, 'failed' => 0],
            ],
        ],
        [
            'name' => 'webhooks',
            'pending' => 11,
            'wait' => 1,
            'processes' => 8,
            'reserved' => 6,
            'delayed' => 0,
            'throughput' => 486,
            'completed' => 402118,
            'failed' => 0,
            'failed_in_window' => 0,
            'latest_error' => null,
            'classes' => [
                ['name' => 'Acme\\Jobs\\PingSatellite', 'pending' => 8, 'reserved' => 6, 'completed' => 96, 'failed' => 0],
                ['name' => 'Acme\\Jobs\\VerifyChecksum', 'pending' => 3, 'reserved' => 0, 'completed' => 44, 'failed' => 0],
            ],
        ],
        [
            'name' => 'notifications',
            'pending' => 148,
            'wait' => 14,
            'processes' => 6,
            'reserved' => 5,
            'delayed' => 24,
            'throughput' => 122,
            'completed' => 51204,
            'failed' => 2,
            'failed_in_window' => 0,
            'latest_error' => 'GuzzleHttp\\Exception\\ConnectException: cURL error 28: Operation timed out after 10000 milliseconds',
            'classes' => [
                ['name' => 'Acme\\Notifications\\WidgetPolished', 'pending' => 96, 'reserved' => 3, 'completed' => 52, 'failed' => 0],
                ['name' => 'Acme\\Notifications\\WorkshopDigest', 'pending' => 41, 'reserved' => 1, 'completed' => 11, 'failed' => 0],
                ['name' => 'Acme\\Jobs\\FlashBeacon', 'pending' => 11, 'reserved' => 1, 'completed' => 27, 'failed' => 2],
            ],
        ],
        [
            'name' => 'assembly',
            'pending' => 612,
            'wait' => 47,
            'processes' => 4,
            'reserved' => 4,
            'delayed' => 8,
            'throughput' => 38,
            'completed' => 9744,
            'failed' => 27,
            'failed_in_window' => 4,
            'latest_error' => 'Illuminate\\Queue\\MaxAttemptsExceededException: Acme\\Jobs\\AssembleSprocket has been attempted too many times',
            'classes' => [
                ['name' => 'Acme\\Jobs\\AssembleSprocket', 'pending' => 402, 'reserved' => 3, 'completed' => 12, 'failed' => 4],
                ['name' => 'Acme\\Jobs\\StraightenFlywheel', 'pending' => 178, 'reserved' => 1, 'completed' => 6, 'failed' => 0],
                ['name' => 'Acme\\Jobs\\ReindexToolbox', 'pending' => 32, 'reserved' => 0, 'completed' => 3, 'failed' => 0],
            ],
        ],
        [
            'name' => 'reports',
            'pending' => 0,
            'wait' => 0,
            'processes' => 1,
            'reserved' => 0,
            'delayed' => 0,
            'throughput' => 0,
            'completed' => 1286,
            'failed' => 0,
            'failed_in_window' => 0,
            'latest_error' => null,
            'classes' => [],
        ],
    ];

    /**
     * Exception summaries attached to failing demo job classes.
     *
     * @var array<string, string>
     */
    protected const EXCEPTIONS = [
        'Acme\\Jobs\\AssembleSprocket' => 'Illuminate\\Queue\\MaxAttemptsExceededException: Acme\\Jobs\\AssembleSprocket has been attempted too many times or run too long',
        'Acme\\Jobs\\FlashBeacon' => 'GuzzleHttp\\Exception\\ConnectException: cURL error 28: Operation timed out after 10000 milliseconds',
    ];

    /**
     * Get mock queue flow data for local UI development.
     *
     * Counters drift with the clock so the dashboard animates like a live
     * installation: sparklines move, activity events stream in, and graph
     * particles keep flowing without a single worker running.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $now = Carbon::now();
        $window = $this->windowSeconds();
        $queues = array_map(fn (array $blueprint): array => $this->queue($blueprint, $now), self::QUEUES);

        $failed = (int) array_sum(array_column($queues, 'failed'));
        $failedInWindow = (int) array_sum(array_column($queues, 'failed_in_window'));
        $currentThroughput = (int) array_sum(array_column($queues, 'current_throughput_per_minute'));

        return [
            'source' => 'mock',
            'meta' => $this->metadata(),
            'generated_at' => $now->toJSON(),
            'summary' => [
                'pending' => (int) array_sum(array_column($queues, 'pending')),
                'processing' => (int) array_sum(array_column($queues, 'processes')),
                'completed' => (int) array_sum(array_column($queues, 'completed')),
                'completed_in_window' => (int) round($currentThroughput * ($window / 60)),
                'failed' => $failed,
                'failed_in_window' => $failedInWindow,
                'window_seconds' => $window,
                'delayed' => (int) array_sum(array_column($queues, 'delayed')),
                'throughput_per_minute' => (int) array_sum(array_column($queues, 'throughput_per_minute')),
                'current_throughput_per_minute' => $currentThroughput,
                'average_wait_seconds' => (int) round(array_sum(array_column($queues, 'wait_seconds')) / max(1, count($queues))),
                'connections' => ['redis'],
            ],
            'nodes' => $this->nodes($queues, $failed, $failedInWindow),
            'edges' => $this->edges($queues),
            'queues' => $queues,
            'events' => $this->events($queues, $now),
        ];
    }

    /**
     * Build a single queue row in the shape the live repositories emit.
     *
     * @param  array<string, mixed>  $blueprint
     * @return array<string, mixed>
     */
    protected function queue(array $blueprint, Carbon $now): array
    {
        $name = (string) $blueprint['name'];
        $window = $this->windowSeconds();
        $pending = $this->drift((int) $blueprint['pending'], $name.'-pending', 0.12);
        $wait = $this->drift((int) $blueprint['wait'], $name.'-wait', 0.25);
        $throughput = $this->drift((int) $blueprint['throughput'], $name.'-throughput', 0.08);
        $currentThroughput = (int) $blueprint['processes'] > 0 ? $throughput : 0;

        return [
            'connection' => 'redis',
            'storage_connection' => 'redis',
            'name' => $name,
            'pending' => $pending,
            'wait_seconds' => $wait,
            'processes' => (int) $blueprint['processes'],
            'reserved' => (int) $blueprint['reserved'],
            'throughput_per_minute' => $throughput,
            'current_throughput_per_minute' => $currentThroughput,
            'recent_activity_per_minute' => (int) round($currentThroughput * 0.6),
            'oldest_pending_seconds' => $wait,
            'estimated_drain_seconds' => $currentThroughput > 0 ? (int) ceil($pending / $currentThroughput * 60) : null,
            'attempts' => (int) $blueprint['failed_in_window'] > 0 ? 3 : 1,
            'completed' => (int) $blueprint['completed'],
            'completed_in_window' => (int) round($currentThroughput * ($window / 60)),
            'delayed' => (int) $blueprint['delayed'],
            'failed' => (int) $blueprint['failed'],
            'failed_in_window' => (int) $blueprint['failed_in_window'],
            'last_failed_at' => (int) $blueprint['failed'] > 0 ? $now->copy()->subSeconds(37)->toJSON() : null,
            'window_seconds' => $window,
            'failure_rate' => $this->failureRate((int) $blueprint['failed'], (int) $blueprint['completed']),
            'latest_error' => $blueprint['latest_error'],
            'jobs' => $this->jobs($blueprint, $now),
            'job_classes' => $this->jobClasses($blueprint),
            'driver' => 'redis',
            'source' => 'mock',
        ];
    }

    /**
     * Build the per-class rollup rendered as job nodes on the graph.
     *
     * @param  array<string, mixed>  $blueprint
     * @return array<int, array<string, mixed>>
     */
    protected function jobClasses(array $blueprint): array
    {
        return array_map(fn (array $class): array => [
            'name' => $class['name'],
            'pending' => (int) $class['pending'],
            'reserved' => (int) $class['reserved'],
            'completed' => (int) $class['completed'],
            'failed' => (int) $class['failed'],
            'attempts' => (int) $class['failed'] > 0 ? 3 : 1,
            'latest_error' => (int) $class['failed'] > 0 ? (self::EXCEPTIONS[$class['name']] ?? null) : null,
        ], $blueprint['classes']);
    }

    /**
     * Build recent job instances, one per non-empty state of each job class.
     * These back the inspector list and the zoomed-in per-job graph nodes.
     *
     * @param  array<string, mixed>  $blueprint
     * @return array<int, array<string, mixed>>
     */
    protected function jobs(array $blueprint, Carbon $now): array
    {
        $jobs = [];
        $age = 4;

        foreach ($blueprint['classes'] as $class) {
            foreach (['failed', 'reserved', 'completed', 'pending'] as $status) {
                if ((int) ($class[$status] ?? 0) <= 0) {
                    continue;
                }

                $age += 7;
                $name = (string) $class['name'];

                $jobs[] = [
                    'id' => substr(sha1($blueprint['name'].$name.$status), 0, 32),
                    'name' => $name,
                    'status' => $status,
                    'connection' => 'redis',
                    'queue' => $blueprint['name'],
                    'attempts' => $status === 'failed' ? 3 : 1,
                    'runtime_seconds' => in_array($status, ['completed', 'failed'], true) ? 1 + ($age % 6) : null,
                    'age_seconds' => $age,
                    'timestamp' => $now->copy()->subSeconds($age)->timestamp,
                    'exception' => $status === 'failed' ? (self::EXCEPTIONS[$name] ?? null) : null,
                    'retryable' => $status === 'failed',
                    // Demo ids do not exist in Horizon's job repository, so the
                    // UI must not link them to the job detail screens.
                    'inspectable' => false,
                ];
            }
        }

        return $jobs;
    }

    /**
     * Build graph nodes matching the layout the live repositories produce.
     *
     * @param  array<int, array<string, mixed>>  $queues
     * @return array<int, array<string, mixed>>
     */
    protected function nodes(array $queues, int $failed, int $failedInWindow): array
    {
        $nodes = [
            $this->node('producer-app', 'producer', config('app.name', 'Application'), 'healthy', [
                'environment' => app()->environment(),
                'throughput' => (int) array_sum(array_column($queues, 'throughput_per_minute')),
            ]),
            $this->node('workers', 'worker', 'Horizon Workers', 'healthy', [
                'processes' => (int) array_sum(array_column($queues, 'processes')),
            ]),
            $this->node('completed', 'result', 'completed', 'healthy'),
            $this->node('failed', 'result', 'failed', match (true) {
                $failedInWindow > 0 => 'critical',
                $failed > 0 => 'warning',
                default => 'healthy',
            }, [
                'failed' => $failed,
                'failed_in_window' => $failedInWindow,
            ]),
        ];

        foreach ($queues as $queue) {
            $nodes[] = $this->node(
                $this->queueId($queue['name']),
                'queue',
                $queue['name'],
                $this->status($queue),
                [
                    'pending' => $queue['pending'],
                    'wait' => $queue['wait_seconds'],
                    'delayed' => $queue['delayed'],
                    'reserved' => $queue['reserved'],
                    'failed' => $queue['failed'],
                    'latest_error' => $queue['latest_error'],
                    'current_throughput' => $queue['current_throughput_per_minute'],
                    'recent_activity' => $queue['recent_activity_per_minute'],
                    'throughput' => $queue['throughput_per_minute'],
                ]
            );
        }

        return $nodes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $queues
     * @return array<int, array<string, mixed>>
     */
    protected function edges(array $queues): array
    {
        $edges = [];
        $failedInWindow = 0;

        foreach ($queues as $queue) {
            $status = $this->status($queue);
            $queueId = $this->queueId($queue['name']);
            $throughput = (int) $queue['current_throughput_per_minute'];
            $failedInWindow += (int) $queue['failed_in_window'];

            $edges[] = $this->edge('producer-app', $queueId, $status, 'dispatch', $throughput);
            $edges[] = $this->edge($queueId, 'workers', $status, 'reserve', $throughput);
        }

        $edges[] = $this->edge('workers', 'completed', 'healthy', 'finish', (int) array_sum(array_column($queues, 'current_throughput_per_minute')));

        if ($failedInWindow > 0) {
            $edges[] = $this->edge('workers', 'failed', 'critical', "{$failedInWindow} failed", 0);
        }

        return $edges;
    }

    /**
     * Build a rolling activity feed. Timestamps are anchored to "now" so every
     * poll returns fresh entries and the graph keeps animating particles.
     *
     * @param  array<int, array<string, mixed>>  $queues
     * @return array<int, array<string, mixed>>
     */
    protected function events(array $queues, Carbon $now): array
    {
        $candidates = [];

        foreach ($queues as $queue) {
            foreach ($queue['jobs'] as $job) {
                $candidates[] = [$queue['name'], $job];
            }
        }

        if ($candidates === []) {
            return [];
        }

        $events = [];

        // Walk backwards in three second steps, cycling through the candidate
        // jobs, so consecutive polls always observe a couple of new events.
        for ($index = 0; $index < 30; $index++) {
            [$queue, $job] = $candidates[($now->timestamp + $index) % count($candidates)];
            $timestamp = $now->timestamp - ($index * 3);
            $state = (string) $job['status'];

            $events[] = [
                'id' => substr(sha1($job['id'].$timestamp), 0, 32),
                'status' => match ($state) {
                    'failed' => 'critical',
                    'pending' => 'warning',
                    default => 'healthy',
                },
                'state' => $state,
                'connection' => 'redis',
                'queue' => $queue,
                'job' => $job['name'],
                'timestamp' => $timestamp,
                'result' => match ($state) {
                    'completed' => 'completed',
                    'failed' => 'failed',
                    'reserved' => 'workers',
                    default => 'queue',
                },
                'label' => sprintf('%s on redis:%s is %s', $job['name'], $queue, $state),
            ];
        }

        return $events;
    }

    /**
     * Nudge a base value with a smooth clock driven wave so the dashboard
     * animates deterministically instead of jittering at random.
     */
    protected function drift(int $base, string $seed, float $amplitude): int
    {
        if ($base === 0) {
            return 0;
        }

        $phase = (crc32($seed) % 360) * (M_PI / 180);

        return max(0, (int) round($base * (1 + ($amplitude * sin((Carbon::now()->timestamp / 20) + $phase)))));
    }

    /**
     * @param  array<string, mixed>  $queue
     */
    protected function status(array $queue): string
    {
        return match (true) {
            $queue['failed_in_window'] > 0 || $queue['wait_seconds'] >= 30 || $queue['pending'] >= 500 => 'critical',
            $queue['wait_seconds'] >= 10 || $queue['pending'] >= 100 || $queue['delayed'] > 0 => 'warning',
            default => 'healthy',
        };
    }

    protected function failureRate(int $failed, int $completed): ?float
    {
        $total = $failed + $completed;

        return $total > 0 ? round(($failed / $total) * 100, 2) : null;
    }

    /**
     * Respect the dashboard time range selector so windowed counters react to
     * the toolbar the same way the live sources do.
     */
    protected function windowSeconds(): int
    {
        try {
            $value = (int) request()->query('window', 900);
        } catch (Throwable) {
            $value = 900;
        }

        return min(2592000, max(60, $value));
    }

    protected function queueId(string $name): string
    {
        return 'queue-'.preg_replace('/[^a-z0-9]+/i', '-', strtolower($name));
    }

    /**
     * @param  array<string, mixed>  $metrics
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
