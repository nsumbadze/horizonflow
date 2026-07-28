<?php

namespace Laravel\Horizon\Tests\Unit;

use Laravel\Horizon\Concerns\InteractsWithCancellation;
use Laravel\Horizon\Contracts\JobControlRepository;
use Mockery;
use Orchestra\Testbench\TestCase;

class InteractsWithCancellationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_a_job_can_acknowledge_cancellation_at_a_safe_checkpoint(): void
    {
        $controls = Mockery::mock(JobControlRepository::class);
        $controls->shouldReceive('cancellationRequested')->once()->with('job-123')->andReturnTrue();
        $controls->shouldReceive('acknowledgeCancellation')->once()->with('job-123')->andReturnTrue();
        $this->app->instance(JobControlRepository::class, $controls);

        $job = new class
        {
            use InteractsWithCancellation;

            public object $job;
        };
        $job->job = new class
        {
            public function getJobId(): string
            {
                return 'job-123';
            }
        };

        $this->assertTrue($job->cancelIfRequested());
    }

    public function test_a_job_without_a_runtime_queue_job_is_not_cancelled(): void
    {
        $job = new class
        {
            use InteractsWithCancellation;
        };

        $this->assertFalse($job->cancelIfRequested());
    }
}
