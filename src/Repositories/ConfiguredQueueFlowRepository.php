<?php

namespace Laravel\Horizon\Repositories;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Laravel\Horizon\Contracts\QueueFlowRepository;

class ConfiguredQueueFlowRepository implements QueueFlowRepository
{
    /**
     * Repository classes keyed by their configuration value.
     *
     * @var array<string, class-string<\Laravel\Horizon\Contracts\QueueFlowRepository>>
     */
    protected const SOURCES = [
        'auto' => UnifiedQueueFlowRepository::class,
        'redis' => RedisQueueFlowRepository::class,
        'database' => DatabaseQueueFlowRepository::class,
        'mock' => MockQueueFlowRepository::class,
    ];

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
        $source = (string) config('horizonxbrain.flow.source', 'redis');

        if (! isset(self::SOURCES[$source])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown horizonxbrain.flow.source [%s]. Expected one of: %s.',
                $source,
                implode(', ', array_keys(self::SOURCES))
            ));
        }

        return self::SOURCES[$source];
    }
}
