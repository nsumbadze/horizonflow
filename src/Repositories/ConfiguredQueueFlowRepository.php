<?php

namespace Laravel\Horizon\Repositories;

use Illuminate\Contracts\Container\Container;
use Laravel\Horizon\Contracts\QueueFlowRepository;

class ConfiguredQueueFlowRepository implements QueueFlowRepository
{
    /**
     * Create a new configured queue flow repository.
     */
    public function __construct(
        protected Container $container,
    ) {
        //
    }

    /**
     * Get queue flow data for the configured source.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return $this->container->make($this->repositoryClass())->get();
    }

    /**
     * Resolve the concrete repository for the configured source.
     *
     * @return class-string<\Laravel\Horizon\Contracts\QueueFlowRepository>
     */
    protected function repositoryClass(): string
    {
        return match (config('horizonxbrain.flow.source', 'mock')) {
            'redis' => RedisQueueFlowRepository::class,
            'database' => DatabaseQueueFlowRepository::class,
            default => MockQueueFlowRepository::class,
        };
    }
}
