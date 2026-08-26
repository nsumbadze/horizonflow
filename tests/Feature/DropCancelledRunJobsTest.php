<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\JobControlRepository;
use Laravel\Horizon\Repositories\RedisJobControlRepository;
use Laravel\Horizon\Tests\Feature\Doubles\FailingJobControlRepository;
use Laravel\Horizon\Tests\Feature\Jobs\ChainedRunJob;
use Laravel\Horizon\Tests\IntegrationTest;

class DropCancelledRunJobsTest extends IntegrationTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['horizon.run.pages'] = [];
    }

    protected function tearDown(): void
    {
        unset($_SERVER['horizon.run.pages']);

        parent::tearDown();
    }

    public function test_an_unreadable_registry_lets_unrelated_work_carry_on(): void
    {
        // Every job in the application passes this lookup, so failing closed
        // here would turn a metadata blip into a queue-wide outage.
        $this->failOn('anyRunCancelled');

        Queue::push(new ChainedRunJob(2));
        $this->work();

        $this->assertSame([1], $_SERVER['horizon.run.pages']);
    }

    public function test_a_job_is_held_back_when_its_run_cannot_be_read(): void
    {
        $this->cancelRun('test-run:2');
        $this->failOn('runCancelled');

        Queue::push(new ChainedRunJob(2));
        $this->work();

        $this->assertSame([], $_SERVER['horizon.run.pages']);
    }

    public function test_a_job_may_be_let_through_when_its_run_cannot_be_read(): void
    {
        config()->set('horizonxflow.cancellation.on_lookup_failure', 'run');

        $this->cancelRun('test-run:2');
        $this->failOn('runCancelled');

        Queue::push(new ChainedRunJob(2));
        $this->work();

        $this->assertSame([1], $_SERVER['horizon.run.pages']);
    }

    public function test_a_drop_still_happens_when_only_its_bookkeeping_fails(): void
    {
        $this->cancelRun('test-run:2');
        $this->failOn('recordRunDrop');

        Queue::push(new ChainedRunJob(2));
        $this->work();

        $this->assertSame([], $_SERVER['horizon.run.pages']);
        $this->assertSame(0, Queue::size('default'));
    }

    protected function cancelRun(string $group): void
    {
        $this->app->make(JobControlRepository::class)->cancelRun($group);
    }

    /**
     * Make the given repository methods fail the way an unreachable Redis would.
     */
    protected function failOn(string ...$methods): void
    {
        $this->app->instance(JobControlRepository::class, new FailingJobControlRepository(
            $this->app->make(RedisJobControlRepository::class),
            $methods
        ));
    }
}
