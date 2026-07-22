<?php

namespace Laravel\Horizon\Repositories;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Laravel\Horizon\Contracts\IncidentRepository;
use Throwable;

class RedisIncidentRepository implements IncidentRepository
{
    protected const KEY = 'horizonxbrain:incidents';

    public function __construct(
        protected RedisFactory $redis,
    ) {
        //
    }

    public function record(array $incident): void
    {
        try {
            $connection = $this->redis->connection('horizon');
            $connection->lpush(self::KEY, json_encode($incident, JSON_THROW_ON_ERROR));
            $connection->ltrim(self::KEY, 0, 199);
        } catch (Throwable) {
            // Operational telemetry must never interrupt queue processing.
        }
    }

    public function recent(int $limit = 50): array
    {
        try {
            return collect($this->redis->connection('horizon')->lrange(self::KEY, 0, max(0, $limit - 1)))
                ->map(fn (string $incident): mixed => json_decode($incident, true))
                ->filter(fn (mixed $incident): bool => is_array($incident))
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
