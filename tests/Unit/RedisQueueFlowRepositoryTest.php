<?php

namespace Laravel\Horizon\Tests\Unit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobControlRepository;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\Repositories\RedisQueueFlowRepository;
use Laravel\Horizon\Tests\UnitTest;
use Mockery;

class RedisQueueFlowRepositoryTest extends UnitTest
{
    protected function tearDown(): void
    {
        RedisQueueFlowRepository::flushQueueKeyCache();

        parent::tearDown();
    }

    public function test_it_uses_pending_jobs_when_workload_is_empty(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1000));

        try {
            $repository = $this->repository(
                [],
                collect([
                    (object) [
                        'connection' => 'redis',
                        'queue' => 'orders-sync',
                        'payload' => json_encode(['pushedAt' => 985]),
                    ],
                ])
            );

            $queues = $repository->exposedQueues();

            $this->assertCount(1, $queues);
            $this->assertSame('redis:orders-sync', $queues[0]['key']);
            $this->assertSame('orders-sync', $queues[0]['name']);
            $this->assertSame(1, $queues[0]['length']);
            $this->assertSame(15, $queues[0]['wait']);
            $this->assertSame(0, $queues[0]['processes']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_does_not_double_count_workload_pending_jobs(): void
    {
        $repository = $this->repository(
            [
                [
                    'name' => 'redis:orders-sync',
                    'length' => 10,
                    'wait' => 3,
                    'processes' => 2,
                ],
            ],
            collect([
                (object) [
                    'connection' => 'redis',
                    'queue' => 'orders-sync',
                    'payload' => json_encode(['pushedAt' => 0]),
                ],
                (object) [
                    'connection' => 'redis',
                    'queue' => 'orders-sync',
                    'payload' => json_encode(['pushedAt' => 0]),
                ],
            ])
        );

        $queue = $repository->exposedQueues()[0];

        $this->assertSame(10, $queue['length']);
        $this->assertSame(3, $queue['wait']);
        $this->assertSame(2, $queue['processes']);
    }

    public function test_it_exposes_failed_jobs_and_latest_error_by_queue(): void
    {
        $repository = $this->repository(
            [],
            collect(),
            collect([
                (object) [
                    'connection' => 'redis',
                    'queue' => 'orders-sync',
                    'exception' => "RuntimeException: Upstream API timed out\nStack trace...",
                ],
            ])
        );

        $queue = $repository->exposedQueues()[0];

        $this->assertSame('redis:orders-sync', $queue['key']);
        $this->assertSame('orders-sync', $queue['name']);
        $this->assertSame(1, $queue['failed']);
        $this->assertSame('RuntimeException: Upstream API timed out', $queue['latest_error']);
    }

    public function test_current_throughput_requires_active_processes(): void
    {
        $idleRepository = $this->repository(
            [
                [
                    'name' => 'redis:orders-sync',
                    'length' => 2,
                    'wait' => 20,
                    'processes' => 0,
                ],
            ],
            collect(),
            null,
            120
        );

        $activeRepository = $this->repository(
            [
                [
                    'name' => 'redis:orders-sync',
                    'length' => 2,
                    'wait' => 20,
                    'processes' => 1,
                ],
            ],
            collect(),
            null,
            120
        );

        $reservedRepository = $this->repository(
            [],
            collect(),
            null,
            120,
            [
                'redis:orders-sync' => [
                    'length' => 0,
                    'delayed' => 0,
                    'reserved' => 1,
                ],
            ]
        );

        $this->assertSame(0, $idleRepository->exposedQueues()[0]['current_throughput']);
        $this->assertSame(120, $activeRepository->exposedQueues()[0]['current_throughput']);
        $this->assertSame(120, $reservedRepository->exposedQueues()[0]['current_throughput']);
    }

    public function test_it_includes_discovered_redis_queue_keys(): void
    {
        $repository = $this->repository(
            [],
            collect(),
            null,
            0,
            [
                'redis:notifications' => [
                    'length' => 3,
                    'delayed' => 2,
                    'reserved' => 1,
                ],
            ]
        );

        $queue = $repository->exposedQueues()[0];

        $this->assertSame('notifications', $queue['name']);
        $this->assertSame(3, $queue['length']);
        $this->assertSame(2, $queue['delayed']);
        $this->assertSame(1, $queue['reserved']);
    }

    public function test_it_includes_configured_redis_queues(): void
    {
        $repository = $this->repository(
            [],
            collect(),
            null,
            0,
            [],
            ['redis:emails']
        );

        $queue = $repository->exposedQueues()[0];

        $this->assertSame('emails', $queue['name']);
        $this->assertSame(0, $queue['length']);
    }

    public function test_it_includes_measured_redis_queues(): void
    {
        $repository = $this->repository(
            [],
            collect(),
            null,
            0,
            [],
            [],
            collect(),
            ['imports']
        );

        $queue = $repository->exposedQueues()[0];

        $this->assertSame('imports', $queue['name']);
        $this->assertSame(0, $queue['length']);
    }

    public function test_recent_completed_jobs_drive_current_activity_before_metric_snapshots(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1000));

        try {
            $repository = $this->repository(
                [],
                collect(),
                null,
                0,
                [],
                [],
                collect([
                    (object) [
                        'connection' => 'redis',
                        'queue' => 'orders-sync',
                        'completed_at' => 995,
                    ],
                ])
            );

            $queue = $repository->exposedQueues()[0];

            $this->assertSame('orders-sync', $queue['name']);
            $this->assertSame(1, $queue['recent_activity']);
            $this->assertSame(1, $queue['current_throughput']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_observations_count_completed_in_window(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));

        try {
            $completed = collect([
                (object) [
                    'id' => 'completed-recent',
                    'name' => 'App\\Jobs\\SendEmail',
                    'connection' => 'redis',
                    'queue' => 'default',
                    'payload' => json_encode(['attempts' => 1, 'pushedAt' => 1_700_000_000 - 30]),
                    'completed_at' => '1700000000.123456',
                ],
                (object) [
                    'id' => 'completed-stale',
                    'name' => 'App\\Jobs\\SendEmail',
                    'connection' => 'redis',
                    'queue' => 'default',
                    'payload' => json_encode(['attempts' => 1, 'pushedAt' => 1_700_000_000 - 5000]),
                    'completed_at' => (string) (1_700_000_000 - 5000),
                ],
            ]);

            $repository = $this->repository(
                [],
                collect(),
                null,
                0,
                [],
                [],
                $completed
            );

            $queues = $repository->exposedQueues();

            $this->assertCount(1, $queues);
            $this->assertSame('default', $queues[0]['name']);
            $this->assertSame(2, $queues[0]['completed']);
            $this->assertSame(1, $queues[0]['completed_in_window']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_completed_in_window_falls_back_when_completed_at_is_missing(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));

        try {
            $completed = collect([
                (object) [
                    'id' => 'completed-phpredis-missing',
                    'name' => 'App\\Jobs\\SendEmail',
                    'connection' => 'redis',
                    'queue' => 'default',
                    'payload' => json_encode(['attempts' => 1, 'pushedAt' => 1_700_000_000 - 60]),
                    'completed_at' => false,
                    'reserved_at' => 1_700_000_000 - 30,
                ],
                (object) [
                    'id' => 'completed-predis-null',
                    'name' => 'App\\Jobs\\SendEmail',
                    'connection' => 'redis',
                    'queue' => 'default',
                    'payload' => json_encode(['attempts' => 1, 'pushedAt' => 1_700_000_000 - 120]),
                    'completed_at' => null,
                    'reserved_at' => 1_700_000_000 - 90,
                ],
            ]);

            $repository = $this->repository(
                [],
                collect(),
                null,
                0,
                [],
                [],
                $completed
            );

            $queues = $repository->exposedQueues();

            $this->assertSame(2, $queues[0]['completed']);
            $this->assertSame(2, $queues[0]['completed_in_window']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_completed_in_window_estimates_from_throughput_when_zset_is_trimmed(): void
    {
        $repository = $this->repository([], collect());

        $estimate = $repository->callCompletedInWindow(window: 900, throughputPerMinute: 4_888, observed: 0);

        $this->assertSame(73_320, $estimate);
    }

    public function test_completed_in_window_prefers_largest_signal(): void
    {
        $repository = $this->repository([], collect());

        $this->assertSame(120, $repository->callCompletedInWindow(window: 60, throughputPerMinute: 30, observed: 120));
    }

    public function test_it_exposes_recent_jobs_and_job_classes_for_queue_inspection(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1000));

        try {
            $repository = $this->repository(
                [],
                collect([
                    (object) [
                        'id' => 'pending-1',
                        'name' => 'App\\Jobs\\SyncCustomer',
                        'connection' => 'redis',
                        'queue' => 'default',
                        'payload' => json_encode(['attempts' => 1, 'pushedAt' => 980]),
                        'created_at' => 980,
                    ],
                ]),
                collect([
                    (object) [
                        'id' => 'failed-1',
                        'name' => 'App\\Jobs\\SyncCustomer',
                        'connection' => 'redis',
                        'queue' => 'default',
                        'payload' => json_encode(['attempts' => 3, 'pushedAt' => 970]),
                        'failed_at' => 995,
                        'exception' => "RuntimeException: Upstream service unavailable\nStack trace...",
                    ],
                ]),
                0,
                [],
                [],
                collect([
                    (object) [
                        'id' => 'completed-1',
                        'name' => 'App\\Jobs\\SendWelcomeEmail',
                        'connection' => 'redis',
                        'queue' => 'default',
                        'payload' => json_encode(['attempts' => 1, 'pushedAt' => 960]),
                        'reserved_at' => 990,
                        'completed_at' => 996,
                    ],
                ])
            );

            $payload = $repository->exposedQueuePayload($repository->exposedQueues()[0]);

            $this->assertCount(3, $payload['jobs']);
            $this->assertSame('failed-1', $payload['jobs'][1]['id']);
            $this->assertTrue($payload['jobs'][1]['retryable']);
            $this->assertSame('RuntimeException: Upstream service unavailable', $payload['jobs'][1]['exception']);

            $this->assertCount(2, $payload['job_classes']);
            $this->assertSame('App\\Jobs\\SyncCustomer', $payload['job_classes'][0]['name']);
            $this->assertSame(1, $payload['job_classes'][0]['pending']);
            $this->assertSame(1, $payload['job_classes'][0]['failed']);
            $this->assertSame(3, $payload['job_classes'][0]['attempts']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_caps_observations_at_the_configured_recent_jobs_limit(): void
    {
        $pending = collect(range(1, 5))->map(fn (int $i): object => (object) [
            'id' => "pending-{$i}",
            'name' => 'App\\Jobs\\SyncCustomer',
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode(['attempts' => 1, 'pushedAt' => 980]),
            'created_at' => 980,
        ]);

        $repository = $this->repository([], $pending);
        $repository->recentJobsLimitOverride = 2;

        $payload = $repository->exposedQueuePayload($repository->exposedQueues()[0]);

        $this->assertCount(2, $payload['jobs']);
    }

    public function test_it_exposes_cancelled_jobs_for_queue_auditing(): void
    {
        $repository = $this->repository(
            [],
            collect(),
            cancelledJobs: collect([
                (object) [
                    'id' => 'cancelled-1',
                    'name' => 'App\\Jobs\\SendWelcomeEmail',
                    'connection' => 'redis',
                    'queue' => 'default',
                    'status' => 'cancelled',
                    'payload' => json_encode(['attempts' => 0, 'pushedAt' => 980]),
                    'cancelled_at' => 995,
                    'cancelled_by' => 'operator:1',
                ],
            ])
        );

        $payload = $repository->exposedQueuePayload($repository->exposedQueues()[0]);

        $this->assertSame('cancelled', $payload['jobs'][0]['status']);
        $this->assertSame('operator:1', $payload['jobs'][0]['cancelled_by']);
        $this->assertFalse($payload['jobs'][0]['cancellable']);
        $this->assertSame(1, $payload['job_classes'][0]['cancelled']);
    }

    public function test_it_exposes_queue_pause_metadata(): void
    {
        $controls = Mockery::mock(JobControlRepository::class);
        $controls->shouldReceive('pausedQueue')
            ->with('redis', 'default')
            ->andReturn([
                'paused_at' => 1_700_000_000,
                'paused_by' => 'operator:1',
            ]);

        $repository = $this->repository(
            [],
            collect(),
            configuredQueues: ['redis:default'],
            controls: $controls,
        );

        $payload = $repository->exposedQueuePayload($repository->exposedQueues()[0]);

        $this->assertTrue($payload['paused']);
        $this->assertSame(1_700_000_000, $payload['paused_at']);
        $this->assertSame('operator:1', $payload['paused_by']);
    }

    public function test_it_caches_discovered_redis_queue_keys_within_ttl(): void
    {
        $repository = $this->repository([], collect());
        $repository->queueKeysTtlOverride = 60;

        $connection = new \stdClass();

        $this->assertSame(['queues:alpha'], $repository->callCachedRedisQueueKeys($connection));
        $this->assertSame(['queues:alpha'], $repository->callCachedRedisQueueKeys($connection));

        $this->assertSame(1, $repository->fetchCount);
    }

    public function test_it_refetches_redis_queue_keys_when_cache_is_disabled(): void
    {
        $repository = $this->repository([], collect());
        $repository->queueKeysTtlOverride = 0;

        $connection = new \stdClass();

        $repository->callCachedRedisQueueKeys($connection);
        $repository->callCachedRedisQueueKeys($connection);

        $this->assertSame(2, $repository->fetchCount);
    }

    /**
     * @param  array<int, array<string, mixed>>  $workloadQueues
     * @param  array<string, array{length: int, delayed: int, reserved: int}>  $discoveredQueues
     * @param  array<int, string>  $configuredQueues
     */
    protected function repository(array $workloadQueues, Collection $pendingJobs, ?Collection $failedJobs = null, int $throughput = 0, array $discoveredQueues = [], array $configuredQueues = [], ?Collection $completedJobs = null, array $measuredQueues = [], ?Collection $cancelledJobs = null, ?JobControlRepository $controls = null): RedisQueueFlowRepository
    {
        $workload = Mockery::mock(WorkloadRepository::class);
        $workload->shouldReceive('get')->byDefault()->andReturn($workloadQueues);

        $jobs = Mockery::mock(JobRepository::class);
        $jobs->shouldReceive('getPending')->byDefault()->with(-1)->andReturn($pendingJobs);
        $jobs->shouldReceive('getCompleted')->byDefault()->with(-1)->andReturn($completedJobs ?? collect());
        $jobs->shouldReceive('getFailed')->byDefault()->with(-1)->andReturn($failedJobs ?? collect());
        $jobs->shouldReceive('getCancelled')->byDefault()->with(-1)->andReturn($cancelledJobs ?? collect());

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('throughputForQueue')->byDefault()->andReturn($throughput);
        $metrics->shouldReceive('measuredQueues')->byDefault()->andReturn($measuredQueues);

        $repository = new class(
            $workload,
            $jobs,
            $metrics,
            Mockery::mock(SupervisorRepository::class),
            null,
            $controls,
        ) extends RedisQueueFlowRepository {
            public array $discoveredQueues = [];
            public array $configuredQueues = [];
            public ?int $recentJobsLimitOverride = null;
            public ?int $queueKeysTtlOverride = null;
            public int $fetchCount = 0;

            public function exposedQueues(): Collection
            {
                return $this->queues();
            }

            public function exposedQueuePayload(array $queue): array
            {
                return $this->queue($queue);
            }

            public function callCachedRedisQueueKeys(mixed $connection): array
            {
                return $this->cachedRedisQueueKeys($connection);
            }

            public function callCompletedInWindow(int $window, int $throughputPerMinute, int $observed): int
            {
                return $this->completedInWindow($window, $throughputPerMinute, $observed);
            }

            protected function configuredRedisQueues(): array
            {
                return $this->configuredQueues;
            }

            protected function discoveredRedisQueues(): array
            {
                return $this->discoveredQueues;
            }

            protected function recentJobsLimit(): int
            {
                return $this->recentJobsLimitOverride ?? parent::recentJobsLimit();
            }

            protected function queueKeysTtl(): int
            {
                return $this->queueKeysTtlOverride ?? parent::queueKeysTtl();
            }

            protected function redisQueueKeys(mixed $connection): array
            {
                $this->fetchCount++;

                return ['queues:alpha'];
            }
        };

        $repository->discoveredQueues = $discoveredQueues;
        $repository->configuredQueues = $configuredQueues;

        return $repository;
    }
}
