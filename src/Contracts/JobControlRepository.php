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
}
