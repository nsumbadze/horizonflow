<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Http\Controllers\RetryController;
use Laravel\Horizon\Jobs\RetryFailedJob;
use Mockery;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RetryControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return ['Laravel\Horizon\HorizonServiceProvider'];
    }

    public function test_it_dispatches_horizon_retry_job_when_failed_job_exists(): void
    {
        Bus::fake();

        $jobs = Mockery::mock(JobRepository::class);
        $jobs->shouldReceive('findFailed')->with('abc-123')->andReturn((object) ['id' => 'abc-123']);

        $controller = new RetryController();
        $controller->store('abc-123', $jobs);

        Bus::assertDispatched(RetryFailedJob::class, fn (RetryFailedJob $job) => $job->id === 'abc-123');
    }

    public function test_it_aborts_with_404_when_failed_job_is_unknown(): void
    {
        Bus::fake();

        $jobs = Mockery::mock(JobRepository::class);
        $jobs->shouldReceive('findFailed')->with('missing')->andReturnNull();

        $controller = new RetryController();

        try {
            $controller->store('missing', $jobs);
            $this->fail('Expected HttpException with status 404.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        Bus::assertNotDispatched(RetryFailedJob::class);
    }
}
