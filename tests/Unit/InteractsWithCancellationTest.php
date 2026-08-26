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

    public function test_a_job_is_cancelled_when_its_run_is_cancelled(): void
    {
        $controls = Mockery::mock(JobControlRepository::class);
        $controls->shouldReceive('cancellationRequested')->once()->with('job-123')->andReturnFalse();
        $controls->shouldReceive('runCancelled')->once()->with('citrus-sync:2')->andReturnTrue();
        $controls->shouldReceive('acknowledgeCancellation')->once()->with('job-123')->andReturnFalse();
        $this->app->instance(JobControlRepository::class, $controls);

        $this->assertTrue($this->jobInRun('citrus-sync:2')->cancelIfRequested());
    }

    public function test_a_job_keeps_running_while_its_run_is_not_cancelled(): void
    {
        $controls = Mockery::mock(JobControlRepository::class);
        $controls->shouldReceive('cancellationRequested')->once()->with('job-123')->andReturnFalse();
        $controls->shouldReceive('runCancelled')->once()->with('citrus-sync:2')->andReturnFalse();
        $this->app->instance(JobControlRepository::class, $controls);

        $this->assertFalse($this->jobInRun('citrus-sync:2')->cancelIfRequested());
    }

    public function test_a_job_belongs_to_no_run_by_default(): void
    {
        $job = new class
        {
            use InteractsWithCancellation;
        };

        $this->assertNull($job->cancellationGroup());
    }

    protected function jobInRun(string $group): object
    {
        $job = new class
        {
            use InteractsWithCancellation;

            public object $job;

            public ?string $group = null;

            public function cancellationGroup(): ?string
            {
                return $this->group;
            }
        };

        $job->group = $group;
        $job->job = new class
        {
            public function getJobId(): string
            {
                return 'job-123';
            }
        };

        return $job;
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
