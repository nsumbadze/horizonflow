<?php

namespace Laravel\Horizon\Tests\Feature;

use Laravel\Horizon\Repositories\DatabaseQueueFlowRepository;
use Laravel\Horizon\Repositories\RedisQueueFlowRepository;
use Laravel\Horizon\Repositories\UnifiedQueueFlowRepository;
use Mockery;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class UnifiedQueueFlowRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return ['Laravel\Horizon\HorizonServiceProvider'];
    }

    public function test_it_reports_per_source_errors_instead_of_swallowing_them(): void
    {
        config(['horizonxbrain.flow.sources' => ['redis', 'database']]);

        $redis = Mockery::mock(RedisQueueFlowRepository::class);
        $redis->shouldReceive('get')->andThrow(new RuntimeException('redis unreachable'));

        $database = Mockery::mock(DatabaseQueueFlowRepository::class);
        $database->shouldReceive('get')->andReturn($this->databasePayload());

        $repository = new UnifiedQueueFlowRepository($redis, $database);

        $payload = $repository->get();

        $this->assertSame(['database'], $payload['sources']);
        $this->assertCount(1, $payload['errors']);
        $this->assertSame('redis', $payload['errors'][0]['source']);
        $this->assertSame('redis unreachable', $payload['errors'][0]['message']);
        $this->assertSame(RuntimeException::class, $payload['errors'][0]['exception']);
    }

    public function test_it_returns_no_errors_when_every_source_succeeds(): void
    {
        config(['horizonxbrain.flow.sources' => ['redis']]);

        $redis = Mockery::mock(RedisQueueFlowRepository::class);
        $redis->shouldReceive('get')->andReturn($this->databasePayload('redis'));

        $database = Mockery::mock(DatabaseQueueFlowRepository::class);

        $repository = new UnifiedQueueFlowRepository($redis, $database);

        $payload = $repository->get();

        $this->assertSame([], $payload['errors']);
    }

    public function test_it_merges_same_named_queue_across_drivers_into_one_row(): void
    {
        config(['horizonxbrain.flow.sources' => ['redis', 'database']]);

        $redis = Mockery::mock(RedisQueueFlowRepository::class);
        $redis->shouldReceive('get')->andReturn($this->payloadWithQueue('redis', 'redis', 'redis', [
            'pending' => 10,
            'processes' => 2,
            'failed' => 0,
            'throughput_per_minute' => 600,
            'wait_seconds' => 4,
        ]));

        $database = Mockery::mock(DatabaseQueueFlowRepository::class);
        $database->shouldReceive('get')->andReturn($this->payloadWithQueue('database', 'mysql', 'database', [
            'pending' => 5,
            'processes' => null,
            'failed' => 1,
            'throughput_per_minute' => null,
            'wait_seconds' => 9,
        ]));

        $payload = (new UnifiedQueueFlowRepository($redis, $database))->get();

        $this->assertCount(1, $payload['queues']);

        $queue = $payload['queues'][0];

        $this->assertSame('default', $queue['name']);
        $this->assertEqualsCanonicalizing(['redis', 'database'], $queue['drivers']);
        $this->assertEqualsCanonicalizing(['redis', 'mysql'], $queue['connections']);
        $this->assertSame(15, $queue['pending']);
        $this->assertSame(1, $queue['failed']);
        $this->assertSame(9, $queue['wait_seconds']);
        $this->assertSame(2, $queue['processes'], 'processes ignores null sources');
        $this->assertSame(600, $queue['throughput_per_minute'], 'throughput ignores null sources');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payloadWithQueue(string $driver, string $connection, string $source, array $overrides): array
    {
        return [
            'source' => $source,
            'summary' => [
                'pending' => $overrides['pending'] ?? 0,
                'processing' => null,
                'completed' => null,
                'failed' => $overrides['failed'] ?? 0,
                'delayed' => 0,
                'throughput_per_minute' => null,
                'current_throughput_per_minute' => 0,
                'average_wait_seconds' => 0,
                'connections' => [$connection],
            ],
            'queues' => [
                array_merge([
                    'name' => 'default',
                    'driver' => $driver,
                    'connection' => $connection,
                    'source' => $source,
                    'storage_connection' => $connection,
                    'pending' => 0,
                    'wait_seconds' => 0,
                    'oldest_pending_seconds' => 0,
                    'processes' => null,
                    'throughput_per_minute' => null,
                    'current_throughput_per_minute' => 0,
                    'recent_activity_per_minute' => 0,
                    'attempts' => 0,
                    'completed' => null,
                    'delayed' => 0,
                    'failed' => 0,
                    'jobs' => [],
                    'job_classes' => [],
                ], $overrides),
            ],
            'events' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function databasePayload(string $source = 'database'): array
    {
        return [
            'source' => $source,
            'summary' => [
                'pending' => 0,
                'processing' => null,
                'completed' => null,
                'failed' => 0,
                'delayed' => 0,
                'throughput_per_minute' => null,
                'current_throughput_per_minute' => 0,
                'average_wait_seconds' => 0,
                'connections' => [],
            ],
            'queues' => [],
            'events' => [],
        ];
    }
}
