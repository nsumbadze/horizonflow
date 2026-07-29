<?php

namespace Laravel\Horizon\Tests\Feature;

use InvalidArgumentException;
use Laravel\Horizon\Repositories\ConfiguredQueueFlowRepository;
use Laravel\Horizon\Repositories\DatabaseQueueFlowRepository;
use Laravel\Horizon\Repositories\MockQueueFlowRepository;
use Laravel\Horizon\Repositories\RedisQueueFlowRepository;
use Laravel\Horizon\Repositories\UnifiedQueueFlowRepository;
use Orchestra\Testbench\TestCase;

class ConfiguredQueueFlowRepositoryTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return ['Laravel\Horizon\HorizonServiceProvider'];
    }


    public function test_it_resolves_redis_repository_for_redis_source(): void
    {
        config(['horizonxflow.flow.source' => 'redis']);

        $this->assertSame(RedisQueueFlowRepository::class, $this->resolveRepositoryClass());
    }

    public function test_it_resolves_database_repository_for_database_source(): void
    {
        config(['horizonxflow.flow.source' => 'database']);

        $this->assertSame(DatabaseQueueFlowRepository::class, $this->resolveRepositoryClass());
    }

    public function test_it_resolves_unified_repository_for_auto_source(): void
    {
        config(['horizonxflow.flow.source' => 'auto']);

        $this->assertSame(UnifiedQueueFlowRepository::class, $this->resolveRepositoryClass());
    }

    public function test_it_resolves_mock_repository_only_when_explicitly_opted_in(): void
    {
        config(['horizonxflow.flow.source' => 'mock']);

        $this->assertSame(MockQueueFlowRepository::class, $this->resolveRepositoryClass());
    }

    public function test_it_throws_for_unknown_source(): void
    {
        config(['horizonxflow.flow.source' => 'kafka']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown horizonxflow.flow.source [kafka]');

        $this->resolveRepositoryClass();
    }

    public function test_it_refuses_mock_source_in_production_environment(): void
    {
        config(['horizonxflow.flow.source' => 'mock']);
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only available in local or testing');

        $this->resolveRepositoryClass();
    }

    public function test_mock_payload_exposes_visual_only_job_and_queue_controls(): void
    {
        $payload = $this->app->make(MockQueueFlowRepository::class)->get();
        $queue = collect($payload['queues'])->firstWhere('name', 'default');
        $jobs = collect($queue['jobs']);

        $this->assertSame('mock', $queue['source']);
        $this->assertFalse($queue['paused']);
        $this->assertTrue($jobs->firstWhere('status', 'pending')['cancellable']);
        $this->assertTrue($jobs->firstWhere('status', 'reserved')['cancellable']);
        $this->assertSame('mock', $jobs->firstWhere('status', 'pending')['source']);
        $this->assertFalse($jobs->firstWhere('status', 'completed')['cancellable']);
    }

    protected function resolveRepositoryClass(): string
    {
        $repository = new class($this->app) extends ConfiguredQueueFlowRepository {
            public function expose(): string
            {
                return $this->repositoryClass();
            }
        };

        return $repository->expose();
    }
}
