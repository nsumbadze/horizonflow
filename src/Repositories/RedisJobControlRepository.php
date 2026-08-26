<?php

namespace Laravel\Horizon\Repositories;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\QueueManager;
use Laravel\Horizon\Contracts\JobControlRepository;
use Laravel\Horizon\JobRunInspector;
use Laravel\Horizon\RedisQueue;

class RedisJobControlRepository implements JobControlRepository
{
    /**
     * Redis hash containing queue pause metadata.
     */
    protected const PAUSED_QUEUES = 'paused_queues';

    /**
     * Redis hash containing cancelled-run metadata, keyed by group.
     */
    protected const CANCELLED_RUNS = 'cancelled_runs';

    /**
     * Create a new Redis job control repository.
     */
    public function __construct(
        protected RedisFactory $redis,
        protected QueueManager $queues,
        protected JobRunInspector $runs,
    ) {
        //
    }

    /**
     * Pause a Redis queue without preventing new jobs from being dispatched.
     *
     * @return array<string, mixed>
     */
    public function pauseQueue(string $connection, string $queue, ?string $operator = null): array
    {
        $this->validateQueue($connection, $queue);

        $field = $this->queueField($connection, $queue);
        $metadata = [
            'connection' => $connection,
            'queue' => $queue,
            'paused_at' => time(),
            'paused_by' => $operator,
        ];

        $this->connection()->hsetnx(
            self::PAUSED_QUEUES,
            $field,
            json_encode($metadata, JSON_THROW_ON_ERROR)
        );

        return $this->pausedQueue($connection, $queue) ?? $metadata;
    }

    /**
     * Resume a paused Redis queue.
     *
     * @return array<string, mixed>
     */
    public function resumeQueue(string $connection, string $queue): array
    {
        $this->validateQueue($connection, $queue);

        return [
            'connection' => $connection,
            'queue' => $queue,
            'resumed' => (bool) $this->connection()->hdel(
                self::PAUSED_QUEUES,
                $this->queueField($connection, $queue)
            ),
        ];
    }

    /**
     * Get the pause metadata for a queue.
     *
     * @return array<string, mixed>|null
     */
    public function pausedQueue(string $connection, string $queue): ?array
    {
        if (! $this->validQueueName($connection) || ! $this->validQueueName($queue)) {
            return null;
        }

        $value = $this->connection()->hget(
            self::PAUSED_QUEUES,
            $this->queueField($connection, $queue)
        );

        if (! is_string($value) || $value === '') {
            return null;
        }

        $metadata = json_decode($value, true);

        return is_array($metadata) ? $metadata : null;
    }

    /**
     * Cancel a pending job or request cooperative cancellation of a reserved job.
     *
     * The ready-list / delayed-set removal is a single Lua operation on the
     * queue Redis connection. If a worker wins that race, no reserved payload
     * is removed; a cooperative cancellation request is recorded instead.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id, ?string $operator = null): array
    {
        if (! preg_match('/\A[A-Za-z0-9-]{1,128}\z/', $id)) {
            return ['action' => 'invalid'];
        }

        $job = $this->job($id);

        if ($job === null) {
            return ['action' => 'not_found'];
        }

        $status = (string) ($job['status'] ?? '');

        if ($status === 'cancelled') {
            return $this->result('cancelled', $job);
        }

        if (in_array($status, ['completed', 'failed'], true)) {
            return $this->result('conflict', $job);
        }

        if ($status === 'pending' && $this->removePendingPayload($job)) {
            $this->markCancelled($id, $operator);

            return $this->result('cancelled', $this->job($id) ?? $job);
        }

        $action = $this->requestCancellation($id, $operator);

        return $this->result($action, $this->job($id) ?? $job);
    }

    /**
     * Determine whether cancellation has been requested for a job.
     */
    public function cancellationRequested(string $id): bool
    {
        if (! preg_match('/\A[A-Za-z0-9-]{1,128}\z/', $id)) {
            return false;
        }

        return (bool) $this->connection()->hexists($id, 'cancellation_requested_at');
    }

    /**
     * Acknowledge a cooperative cancellation request.
     */
    public function acknowledgeCancellation(string $id): bool
    {
        if (! $this->cancellationRequested($id)) {
            return false;
        }

        return (bool) $this->connection()->hsetnx(
            $id,
            'cancellation_acknowledged_at',
            str_replace(',', '.', microtime(true))
        );
    }

    /**
     * Atomically record a cancellation request unless the job is terminal.
     */
    protected function requestCancellation(string $id, ?string $operator): string
    {
        $result = $this->connection()->eval(
            <<<'LUA'
                local status = redis.call('hget', KEYS[1], 'status')

                if not status then
                    return 'not_found'
                end

                if status == 'cancelled' then
                    return 'cancelled'
                end

                if status == 'completed' or status == 'failed' then
                    return 'conflict'
                end

                redis.call('hsetnx', KEYS[1], 'cancellation_requested_at', ARGV[1])

                if ARGV[2] ~= '' then
                    redis.call('hsetnx', KEYS[1], 'cancellation_requested_by', ARGV[2])
                end

                return 'cancellation_requested'
LUA,
            1,
            $id,
            str_replace(',', '.', microtime(true)),
            $operator ?? ''
        );

        return is_string($result) ? $result : 'conflict';
    }

    /**
     * Move a removed pending job into the retained cancelled-job index.
     */
    protected function markCancelled(string $id, ?string $operator): void
    {
        $now = str_replace(',', '.', microtime(true));
        $expiresAt = time() + ((int) config('horizon.trim.completed', 60) * 60);

        $this->connection()->eval(
            <<<'LUA'
                redis.call('zrem', KEYS[1], ARGV[1])
                redis.call('zadd', KEYS[2], tonumber(ARGV[2]) * -1, ARGV[1])
                redis.call('hset', KEYS[3], 'status', 'cancelled', 'cancelled_at', ARGV[2], 'updated_at', ARGV[2])

                if ARGV[3] ~= '' then
                    redis.call('hset', KEYS[3], 'cancelled_by', ARGV[3])
                end

                redis.call('expireat', KEYS[3], tonumber(ARGV[4]))

                return 1
LUA,
            3,
            'pending_jobs',
            'cancelled_jobs',
            $id,
            $id,
            $now,
            $operator ?? '',
            $expiresAt
        );
    }

    /**
     * Remove a payload from the ready list or delayed set.
     *
     * @param  array<string, string|null>  $job
     */
    protected function removePendingPayload(array $job): bool
    {
        $connection = (string) ($job['connection'] ?? '');
        $queue = (string) ($job['queue'] ?? '');
        $payload = $job['payload'] ?? null;

        if (! is_string($payload) || $payload === '') {
            return false;
        }

        try {
            $this->validateQueue($connection, $queue);
            $redisQueue = $this->queues->connection($connection);

            return $redisQueue instanceof RedisQueue
                && $redisQueue->removePending($queue, $payload);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get control-relevant job metadata.
     *
     * @return array<string, string|null>|null
     */
    protected function job(string $id): ?array
    {
        $fields = [
            'id',
            'connection',
            'queue',
            'status',
            'payload',
            'cancelled_at',
            'cancelled_by',
            'cancellation_requested_at',
            'cancellation_requested_by',
            'cancellation_acknowledged_at',
        ];
        $values = $this->connection()->hmget($id, $fields);

        if (! is_array($values) || ($values[0] ?? null) === null) {
            return null;
        }

        return array_combine($fields, array_values($values));
    }

    /**
     * @param  array<string, string|null>  $job
     * @return array<string, mixed>
     */
    protected function result(string $action, array $job): array
    {
        return [
            'action' => $action,
            'id' => $job['id'] ?? null,
            'status' => $job['status'] ?? null,
            'cancelled_at' => $job['cancelled_at'] ?? null,
            'cancelled_by' => $job['cancelled_by'] ?? null,
            'cancellation_requested_at' => $job['cancellation_requested_at'] ?? null,
            'cancellation_requested_by' => $job['cancellation_requested_by'] ?? null,
        ];
    }

    /**
     * Cancel a whole run, purging its pending jobs and blocking the rest.
     *
     * The mark is written before anything is purged, so a job dispatched while
     * the purge is walking the pending index is still refused when a worker
     * picks it up.
     *
     * @return array<string, mixed>
     */
    public function cancelRun(string $group, ?string $operator = null, ?int $ttl = null): array
    {
        if (! $this->runs->valid($group)) {
            throw new \InvalidArgumentException('The run group is invalid.');
        }

        $ttl = max(60, $ttl ?? (int) config('horizonxflow.cancellation.run_ttl', 3600));

        $existing = $this->cancelledRun($group);

        $metadata = [
            'group' => $group,
            'cancelled_at' => $existing['cancelled_at'] ?? time(),
            'cancelled_by' => $existing['cancelled_by'] ?? $operator,
            'expires_at' => time() + $ttl,
            'purged' => (int) ($existing['purged'] ?? 0),
            'dropped' => (int) ($existing['dropped'] ?? 0),
        ];

        $this->writeRun($metadata);

        $purge = $this->purgeRun($group, $operator);

        $metadata['purged'] += $purge['purged'];
        $this->writeRun($metadata);

        return array_merge($metadata, [
            'scanned' => $purge['scanned'],
            'truncated' => $purge['truncated'],
            'purged_now' => $purge['purged'],
        ]);
    }

    /**
     * Lift a run cancellation so its jobs may run again.
     */
    public function releaseRun(string $group): bool
    {
        if (! $this->runs->valid($group)) {
            return false;
        }

        return (bool) $this->connection()->hdel(self::CANCELLED_RUNS, $group);
    }

    /**
     * Determine whether the given run is currently cancelled.
     */
    public function runCancelled(string $group): bool
    {
        return $this->cancelledRun($group) !== null;
    }

    /**
     * Get the metadata for a cancelled run.
     *
     * @return array<string, mixed>|null
     */
    public function cancelledRun(string $group): ?array
    {
        if (! $this->runs->valid($group)) {
            return null;
        }

        $value = $this->connection()->hget(self::CANCELLED_RUNS, $group);

        if (! is_string($value) || $value === '') {
            return null;
        }

        $metadata = json_decode($value, true);

        if (! is_array($metadata)) {
            return null;
        }

        if ((int) ($metadata['expires_at'] ?? 0) <= time()) {
            $this->connection()->hdel(self::CANCELLED_RUNS, $group);

            return null;
        }

        return $metadata;
    }

    /**
     * Get every run that is currently cancelled.
     *
     * Entries that have expired are dropped as they are read, so the hash does
     * not accumulate marks nobody lifted.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cancelledRuns(): array
    {
        $entries = $this->connection()->hgetall(self::CANCELLED_RUNS);

        if (! is_array($entries)) {
            return [];
        }

        $active = [];
        $expired = [];

        foreach ($entries as $group => $value) {
            $metadata = is_string($value) ? json_decode($value, true) : null;

            if (! is_array($metadata) || (int) ($metadata['expires_at'] ?? 0) <= time()) {
                $expired[] = (string) $group;

                continue;
            }

            $active[] = $metadata;
        }

        if ($expired !== []) {
            $this->connection()->hdel(self::CANCELLED_RUNS, ...$expired);
        }

        usort($active, fn ($a, $b) => ($b['cancelled_at'] ?? 0) <=> ($a['cancelled_at'] ?? 0));

        return $active;
    }

    /**
     * Determine whether any run is currently cancelled.
     */
    public function anyRunCancelled(): bool
    {
        return (int) $this->connection()->hlen(self::CANCELLED_RUNS) > 0;
    }

    /**
     * Record that a job was dropped because its run is cancelled.
     */
    public function recordRunDrop(string $group, ?string $id = null): void
    {
        $metadata = $this->cancelledRun($group);

        if ($metadata === null) {
            return;
        }

        $metadata['dropped'] = (int) ($metadata['dropped'] ?? 0) + 1;

        $this->writeRun($metadata);

        if ($id !== null && preg_match('/\A[A-Za-z0-9-]{1,128}\z/', $id)) {
            $this->markCancelled($id, $metadata['cancelled_by'] ?? null);
        }
    }

    /**
     * Write the given run metadata back to Redis.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function writeRun(array $metadata): void
    {
        $this->connection()->hset(
            self::CANCELLED_RUNS,
            (string) $metadata['group'],
            json_encode($metadata, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Remove the pending jobs that belong to the given run.
     *
     * @return array{purged: int, scanned: int, truncated: bool}
     */
    protected function purgeRun(string $group, ?string $operator): array
    {
        $limit = max(0, (int) config('horizonxflow.cancellation.purge_limit', 5000));

        if ($limit === 0) {
            return ['purged' => 0, 'scanned' => 0, 'truncated' => false];
        }

        $ids = $this->connection()->zrange('pending_jobs', 0, $limit - 1);
        $ids = is_array($ids) ? $ids : [];

        $purged = 0;

        foreach ($ids as $id) {
            $id = (string) $id;
            $job = $this->job($id);

            if ($job === null || ($job['status'] ?? null) !== 'pending') {
                continue;
            }

            $payload = json_decode((string) ($job['payload'] ?? ''), true);

            if (! is_array($payload) || $this->runs->groupForPayload($payload) !== $group) {
                continue;
            }

            if ($this->removePendingPayload($job)) {
                $this->markCancelled($id, $operator);
                $purged++;
            }
        }

        return [
            'purged' => $purged,
            'scanned' => count($ids),
            'truncated' => count($ids) >= $limit,
        ];
    }

    /**
     * Reject unknown connections and queue names that could address arbitrary keys.
     */
    protected function validateQueue(string $connection, string $queue): void
    {
        $connections = (array) config('queue.connections', []);
        $configuration = $connections[$connection] ?? null;

        if (! is_array($configuration) || ($configuration['driver'] ?? null) !== 'redis') {
            throw new \InvalidArgumentException('Only configured Redis queue connections may be controlled.');
        }

        if (! $this->validQueueName($queue)) {
            throw new \InvalidArgumentException('The queue name is invalid.');
        }
    }

    protected function validQueueName(string $value): bool
    {
        return (bool) preg_match('/\A[A-Za-z0-9._-]{1,128}\z/', $value);
    }

    protected function queueField(string $connection, string $queue): string
    {
        return $connection.':'.$queue;
    }

    /**
     * Get the Horizon Redis metadata connection.
     */
    protected function connection(): mixed
    {
        return $this->redis->connection('horizon');
    }
}
