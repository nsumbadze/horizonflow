<?php

namespace Laravel\Horizon\Repositories;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laravel\Horizon\Contracts\QueueFlowRepository;

class CachedQueueFlowRepository implements QueueFlowRepository
{
    /**
     * Cache key used to memoize the full flow payload between requests.
     */
    public const CACHE_KEY = 'horizonxbrain:flow:payload';

    /**
     * Create a new cached queue flow repository.
     */
    public function __construct(
        protected QueueFlowRepository $inner,
        protected CacheRepository $cache,
    ) {
        //
    }

    /**
     * Get queue flow data, reusing a short-lived cached payload when configured.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $ttl = $this->payloadTtl();

        if ($ttl <= 0) {
            return $this->inner->get();
        }

        return $this->cache->remember(self::CACHE_KEY, $ttl, fn (): array => $this->inner->get());
    }

    protected function payloadTtl(): int
    {
        return max(0, (int) config('horizonxbrain.flow.cache.payload_ttl', 1));
    }
}
