<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\JobControlRepository;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Tests\Feature\Jobs\BasicJob;
use Laravel\Horizon\Tests\Feature\Jobs\ChainedRunJob;
use Laravel\Horizon\Tests\IntegrationTest;

class RunCancellationTest extends IntegrationTest
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

    public function test_cancelling_a_run_purges_its_pending_jobs(): void
    {
        Queue::push(new ChainedRunJob(2));
        Queue::push(new ChainedRunJob(2, 4));
        $other = Queue::push(new ChainedRunJob(9));
        $unrelated = Queue::push(new BasicJob);

        $result = $this->controls()->cancelRun('test-run:2', 'operator:1');

        $this->assertSame(2, $result['purged']);
        $this->assertSame(2, Queue::size('default'));
        $this->assertSame('pending', $this->job($other)->status);
        $this->assertSame('pending', $this->job($unrelated)->status);
    }

    public function test_a_purged_job_is_retained_as_cancelled(): void
    {
        $id = Queue::push(new ChainedRunJob(2));

        $this->controls()->cancelRun('test-run:2', 'operator:1');

        $job = $this->job($id);
        $this->assertSame('cancelled', $job->status);
        $this->assertSame('operator:1', $job->cancelled_by);
    }

    public function test_a_job_of_a_cancelled_run_is_dropped_instead_of_run(): void
    {
        $this->controls()->cancelRun('test-run:2');

        Queue::push(new ChainedRunJob(2));
        $this->work();

        $this->assertSame([], $_SERVER['horizon.run.pages']);
        $this->assertSame(0, Queue::size('default'));
    }

    public function test_cancelling_a_run_stops_a_self_chaining_walk(): void
    {
        Queue::push(new ChainedRunJob(2));

        $this->work();
        $this->assertSame([1], $_SERVER['horizon.run.pages']);
        $this->assertSame(1, Queue::size('default'));

        // Page 2 is already queued. Cancelling the run must stop it, and with
        // it every page that would have followed.
        $this->controls()->cancelRun('test-run:2');

        $this->work(4);

        $this->assertSame([1], $_SERVER['horizon.run.pages']);
        $this->assertSame(0, Queue::size('default'));
    }

    public function test_an_unrelated_job_still_runs_while_a_run_is_cancelled(): void
    {
        $this->controls()->cancelRun('test-run:2');

        Queue::push(new ChainedRunJob(9));
        $this->work();

        $this->assertSame([1], $_SERVER['horizon.run.pages']);
    }

    public function test_lifting_a_run_lets_its_jobs_run_again(): void
    {
        $this->controls()->cancelRun('test-run:2');
        $this->assertTrue($this->controls()->releaseRun('test-run:2'));

        Queue::push(new ChainedRunJob(2));
        $this->work();

        $this->assertSame([1], $_SERVER['horizon.run.pages']);
    }

    public function test_it_lists_cancelled_runs_with_their_counters(): void
    {
        Queue::push(new ChainedRunJob(2));
        $this->controls()->cancelRun('test-run:2', 'operator:1');

        Queue::push(new ChainedRunJob(2, 7));
        $this->work();

        $runs = $this->controls()->cancelledRuns();

        $this->assertCount(1, $runs);
        $this->assertSame('test-run:2', $runs[0]['group']);
        $this->assertSame('operator:1', $runs[0]['cancelled_by']);
        $this->assertSame(1, $runs[0]['purged']);
        $this->assertSame(1, $runs[0]['dropped']);
    }

    public function test_a_cancellation_stops_standing_once_it_expires(): void
    {
        $this->controls()->cancelRun('test-run:2', null, 60);

        $this->assertTrue($this->controls()->runCancelled('test-run:2'));

        $this->travelTo(now()->addSeconds(61));

        $this->assertFalse($this->controls()->runCancelled('test-run:2'));
        $this->assertSame([], $this->controls()->cancelledRuns());

        $this->travelBack();
    }

    public function test_no_run_is_cancelled_by_default(): void
    {
        $this->assertFalse($this->controls()->anyRunCancelled());
        $this->assertFalse($this->controls()->runCancelled('test-run:2'));
    }

    public function test_it_refuses_an_unaddressable_group(): void
    {
        $this->expectExceptionMessage('The run group is invalid.');

        $this->controls()->cancelRun('test run/2');
    }

    protected function job(string $id): object
    {
        return $this->app->make(JobRepository::class)->getJobs([$id])->first();
    }

    protected function controls(): JobControlRepository
    {
        return $this->app->make(JobControlRepository::class);
    }
}
