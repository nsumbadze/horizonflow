<?php

namespace Laravel\Horizon\Tests\Feature\Doubles;

use Laravel\Horizon\Contracts\JobControlRepository;
use RuntimeException;

/**
 * A control repository that behaves normally except for chosen methods.
 *
 * RedisQueue resolves this same contract while popping, so a bare mock would
 * break the worker before a job is ever reserved. Delegating keeps everything
 * else working while one lookup fails the way an unreachable Redis would.
 */
class FailingJobControlRepository implements JobControlRepository
{
    /**
     * @param  array<int, string>  $failing
     */
    public function __construct(
        protected JobControlRepository $inner,
        protected array $failing = [],
    ) {
    }

    protected function guard(string $method): void
    {
        if (in_array($method, $this->failing, true)) {
            throw new RuntimeException("metadata redis is unreachable [{$method}]");
        }
    }

    public function pauseQueue(string $connection, string $queue, ?string $operator = null): array
    {
        $this->guard(__FUNCTION__);

        return $this->inner->pauseQueue($connection, $queue, $operator);
    }

    public function resumeQueue(string $connection, string $queue): array
    {
        $this->guard(__FUNCTION__);

        return $this->inner->resumeQueue($connection, $queue);
    }

    public function pausedQueue(string $connection, string $queue): ?array
    {
        $this->guard(__FUNCTION__);

        return $this->inner->pausedQueue($connection, $queue);
    }

    public function cancel(string $id, ?string $operator = null): array
    {
        $this->guard(__FUNCTION__);

        return $this->inner->cancel($id, $operator);
    }

    public function cancellationRequested(string $id): bool
    {
        $this->guard(__FUNCTION__);

        return $this->inner->cancellationRequested($id);
    }

    public function acknowledgeCancellation(string $id): bool
    {
        $this->guard(__FUNCTION__);

        return $this->inner->acknowledgeCancellation($id);
    }

    public function cancelRun(string $group, ?string $operator = null, ?int $ttl = null): array
    {
        $this->guard(__FUNCTION__);

        return $this->inner->cancelRun($group, $operator, $ttl);
    }

    public function releaseRun(string $group): bool
    {
        $this->guard(__FUNCTION__);

        return $this->inner->releaseRun($group);
    }

    public function runCancelled(string $group): bool
    {
        $this->guard(__FUNCTION__);

        return $this->inner->runCancelled($group);
    }

    public function cancelledRun(string $group): ?array
    {
        $this->guard(__FUNCTION__);

        return $this->inner->cancelledRun($group);
    }

    public function cancelledRuns(): array
    {
        $this->guard(__FUNCTION__);

        return $this->inner->cancelledRuns();
    }

    public function anyRunCancelled(): bool
    {
        $this->guard(__FUNCTION__);

        return $this->inner->anyRunCancelled();
    }

    public function recordRunDrop(string $group, ?string $id = null): void
    {
        $this->guard(__FUNCTION__);

        $this->inner->recordRunDrop($group, $id);
    }
}
