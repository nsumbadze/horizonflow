<?php

namespace Laravel\Horizon\Tests\Unit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\Repositories\RedisQueueFlowRepository;
use Laravel\Horizon\Tests\UnitTest;
use Mockery;

class RedisQueueFlowRepositoryTest extends UnitTest
{
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

    /**
     * @param  array<int, array<string, mixed>>  $workloadQueues
     * @param  array<string, array{length: int, delayed: int, reserved: int}>  $discoveredQueues
     * @param  array<int, string>  $configuredQueues
     */
    protected function repository(array $workloadQueues, Collection $pendingJobs, ?Collection $failedJobs = null, int $throughput = 0, array $discoveredQueues = [], array $configuredQueues = []): RedisQueueFlowRepository
    {
        $workload = Mockery::mock(WorkloadRepository::class);
        $workload->shouldReceive('get')->once()->andReturn($workloadQueues);

        $jobs = Mockery::mock(JobRepository::class);
        $jobs->shouldReceive('getPending')->once()->with(-1)->andReturn($pendingJobs);
        $jobs->shouldReceive('getCompleted')->once()->with(-1)->andReturn(collect());
        $jobs->shouldReceive('getFailed')->once()->with(-1)->andReturn($failedJobs ?? collect());

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('throughputForQueue')->byDefault()->andReturn($throughput);

        $repository = new class(
            $workload,
            $jobs,
            $metrics,
            Mockery::mock(SupervisorRepository::class)
        ) extends RedisQueueFlowRepository {
            public array $discoveredQueues = [];
            public array $configuredQueues = [];

            public function exposedQueues(): Collection
            {
                return $this->queues();
            }

            protected function configuredRedisQueues(): array
            {
                return $this->configuredQueues;
            }

            protected function discoveredRedisQueues(): array
            {
                return $this->discoveredQueues;
            }
        };

        $repository->discoveredQueues = $discoveredQueues;
        $repository->configuredQueues = $configuredQueues;

        return $repository;
    }
}
