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
