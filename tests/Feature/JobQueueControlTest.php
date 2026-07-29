<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\JobControlRepository;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Tests\Feature\Jobs\BasicJob;
use Laravel\Horizon\Tests\IntegrationTest;

class JobQueueControlTest extends IntegrationTest
{
    public function test_a_paused_queue_retains_jobs_until_it_is_resumed(): void
    {
        $controls = $this->app->make(JobControlRepository::class);
        $controls->pauseQueue('redis', 'default', 'operator:1');

        $id = Queue::push(new BasicJob);

        $this->work();

        $this->assertSame('pending', $this->job($id)->status);
        $this->assertSame(1, Queue::size('default'));
        $this->assertSame('operator:1', $controls->pausedQueue('redis', 'default')['paused_by']);

        $controls->resumeQueue('redis', 'default');
        $this->work();

        $this->assertSame('completed', $this->job($id)->status);
        $this->assertSame(0, Queue::size('default'));
    }

    public function test_a_pending_job_is_atomically_removed_and_retained_as_cancelled(): void
    {
        $id = Queue::push(new BasicJob);

        $result = $this->app->make(JobControlRepository::class)
            ->cancel($id, 'operator:1');

        $this->assertSame('cancelled', $result['action']);
        $this->assertSame(0, Queue::size('default'));
        $this->assertSame(0, $this->app->make(JobRepository::class)->countPending());
        $this->assertSame(1, $this->app->make(JobRepository::class)->countCancelled());

        $job = $this->job($id);
        $this->assertSame('cancelled', $job->status);
        $this->assertSame('operator:1', $job->cancelled_by);
    }

    public function test_a_delayed_job_is_atomically_removed_and_retained_as_cancelled(): void
    {
        $id = Queue::later(3600, new BasicJob);

        $result = $this->app->make(JobControlRepository::class)
            ->cancel($id, 'operator:1');

        $this->assertSame('cancelled', $result['action']);
        $this->assertSame(0, Queue::size('default'));
        $this->assertSame('cancelled', $this->job($id)->status);
    }

    public function test_a_reserved_job_receives_a_cooperative_cancellation_request(): void
    {
        $id = Queue::push(new BasicJob);
        $reserved = Queue::connection('redis')->pop('default');

        $this->assertNotNull($reserved);
        $this->assertSame('reserved', $this->job($id)->status);

        $controls = $this->app->make(JobControlRepository::class);
        $result = $controls->cancel($id, 'operator:1');

        $this->assertSame('cancellation_requested', $result['action']);
        $this->assertTrue($controls->cancellationRequested($id));
        $this->assertSame('operator:1', $this->job($id)->cancellation_requested_by);

        $controls->acknowledgeCancellation($id);
        $reserved->delete();

        $this->assertSame('cancelled', $this->job($id)->status);
        $this->assertSame(1, $this->app->make(JobRepository::class)->countCancelled());
    }

    public function test_terminal_jobs_cannot_be_cancelled(): void
    {
        $id = Queue::push(new BasicJob);
        $this->work();

        $result = $this->app->make(JobControlRepository::class)->cancel($id);

        $this->assertSame('conflict', $result['action']);
        $this->assertSame('completed', $this->job($id)->status);
    }

    protected function job(string $id): object
    {
        return $this->app->make(JobRepository::class)->getJobs([$id])->first();
    }
}
