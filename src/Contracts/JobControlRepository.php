<?php

namespace Laravel\Horizon\Contracts;

interface JobControlRepository
{
    /**
     * Pause a Redis queue without preventing new jobs from being dispatched.
     *
     * @return array<string, mixed>
     */
    public function pauseQueue(string $connection, string $queue, ?string $operator = null): array;

    /**
     * Resume a paused Redis queue.
     *
     * @return array<string, mixed>
     */
    public function resumeQueue(string $connection, string $queue): array;

    /**
     * Get the pause metadata for a queue.
     *
     * @return array<string, mixed>|null
     */
    public function pausedQueue(string $connection, string $queue): ?array;

    /**
     * Cancel a pending job or request cooperative cancellation of a reserved job.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id, ?string $operator = null): array;

    /**
     * Determine whether cancellation has been requested for a job.
     */
    public function cancellationRequested(string $id): bool;

    /**
     * Acknowledge a cooperative cancellation request.
     */
    public function acknowledgeCancellation(string $id): bool;

    /**
     * Cancel a whole run, purging its pending jobs and blocking the rest.
     *
     * @return array<string, mixed>
     */
    public function cancelRun(string $group, ?string $operator = null, ?int $ttl = null): array;

    /**
     * Lift a run cancellation so its jobs may run again.
     */
    public function releaseRun(string $group): bool;

    /**
     * Determine whether the given run is currently cancelled.
     */
    public function runCancelled(string $group): bool;

    /**
     * Get the metadata for a cancelled run.
     *
     * @return array<string, mixed>|null
     */
    public function cancelledRun(string $group): ?array;

    /**
     * Get every run that is currently cancelled.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cancelledRuns(): array;

    /**
     * Determine whether any run is currently cancelled.
     *
     * Workers call this before doing any payload work, so it must stay cheap.
     */
    public function anyRunCancelled(): bool;

    /**
     * Record that a job was dropped because its run is cancelled.
     */
    public function recordRunDrop(string $group, ?string $id = null): void;
}
