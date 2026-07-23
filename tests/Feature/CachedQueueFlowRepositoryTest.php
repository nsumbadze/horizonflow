<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laravel\Horizon\Contracts\QueueFlowRepository;
use Laravel\Horizon\Repositories\CachedQueueFlowRepository;
use Mockery;
use Orchestra\Testbench\TestCase;

class CachedQueueFlowRepositoryTest extends TestCase
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

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    public function test_it_serves_cached_payload_within_ttl(): void
    {
        config(['horizonxflow.flow.cache.payload_ttl' => 10]);

        $inner = Mockery::mock(QueueFlowRepository::class);
        $inner->shouldReceive('get')->once()->andReturn(['source' => 'redis']);

        $cache = $this->app->make(CacheRepository::class);
        $cache->forget(CachedQueueFlowRepository::CACHE_KEY);

        $repository = new CachedQueueFlowRepository($inner, $cache);

        $this->assertSame(['source' => 'redis'], $repository->get());
        $this->assertSame(['source' => 'redis'], $repository->get());
    }

    public function test_it_bypasses_cache_when_ttl_is_zero(): void
    {
        config(['horizonxflow.flow.cache.payload_ttl' => 0]);

        $inner = Mockery::mock(QueueFlowRepository::class);
        $inner->shouldReceive('get')->twice()->andReturn(['source' => 'redis']);

        $cache = $this->app->make(CacheRepository::class);

        $repository = new CachedQueueFlowRepository($inner, $cache);

        $repository->get();
        $repository->get();
    }
}
