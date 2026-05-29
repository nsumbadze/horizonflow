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
     * The cache key is partitioned by the request `window` parameter so a user
     * toggling between Last 5m and Last 1h doesn't see stale windowed counts.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $ttl = $this->payloadTtl();

        if ($ttl <= 0) {
            return $this->inner->get();
        }

        $window = (int) request()->query('window', 900);

        return $this->cache->remember(
            self::CACHE_KEY.':'.$window,
            $ttl,
            fn (): array => $this->inner->get()
        );
    }

    protected function payloadTtl(): int
    {
        return max(0, (int) config('horizonxbrain.flow.cache.payload_ttl', 1));
    }
}
