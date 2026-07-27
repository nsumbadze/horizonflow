<?php

namespace Laravel\Horizon\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Http\Controllers\RetryController;
use Laravel\Horizon\JobParameterInspector;
use Laravel\Horizon\Jobs\RetryFailedJob;
use Laravel\Horizon\Tests\Feature\Jobs\JobWithParameters;
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

        $controller = new RetryController();
        $controller->store('abc-123', new Request, $this->jobs('abc-123'), $this->inspector());

        Bus::assertDispatched(
            RetryFailedJob::class,
            fn (RetryFailedJob $job) => $job->id === 'abc-123' && $job->parameters === []
        );
    }

    public function test_it_aborts_with_404_when_failed_job_is_unknown(): void
    {
        Bus::fake();

        $jobs = Mockery::mock(JobRepository::class);
        $jobs->shouldReceive('findFailed')->with('missing')->andReturnNull();

        $controller = new RetryController();

        try {
            $controller->store('missing', new Request, $jobs, $this->inspector());
            $this->fail('Expected HttpException with status 404.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        Bus::assertNotDispatched(RetryFailedJob::class);
    }

    public function test_it_dispatches_horizon_retry_job_with_parameter_overrides(): void
    {
        Bus::fake();

        $request = $this->requestWithParameters(['name' => 'export', 'attempts' => '9']);

        $controller = new RetryController();
        $controller->store('abc-123', $request, $this->jobs('abc-123'), $this->inspector());

        Bus::assertDispatched(
            RetryFailedJob::class,
            fn (RetryFailedJob $job) => $job->parameters === ['name' => 'export', 'attempts' => '9']
        );
    }

    public function test_it_aborts_with_422_when_parameter_overrides_are_invalid(): void
    {
        Bus::fake();

        $request = $this->requestWithParameters(['attempts' => 'not-a-number']);

        $controller = new RetryController();

        try {
            $controller->store('abc-123', $request, $this->jobs('abc-123'), $this->inspector());
            $this->fail('Expected HttpException with status 422.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame('The [attempts] parameter must be numeric.', $exception->getMessage());
        }

        Bus::assertNotDispatched(RetryFailedJob::class);
    }

    public function test_it_returns_the_editable_parameters_of_a_failed_job(): void
    {
        $controller = new RetryController();

        $description = $controller->parameters('abc-123', $this->jobs('abc-123'), $this->inspector());

        $this->assertTrue($description['editable']);
        $this->assertSame(JobWithParameters::class, $description['class']);
        $this->assertSame('import', collect($description['parameters'])->firstWhere('name', 'name')['value']);
    }

    public function test_it_aborts_with_404_when_requesting_parameters_of_an_unknown_job(): void
    {
        $jobs = Mockery::mock(JobRepository::class);
        $jobs->shouldReceive('findFailed')->with('missing')->andReturnNull();

        $controller = new RetryController();

        try {
            $controller->parameters('missing', $jobs, $this->inspector());
            $this->fail('Expected HttpException with status 404.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    /**
     * Build a JSON request carrying the given parameter overrides.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function requestWithParameters(array $parameters): Request
    {
        $request = Request::create('/', 'POST', [], [], [], [], json_encode(['parameters' => $parameters]));

        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    /**
     * Get a job repository returning a failed job with editable parameters.
     */
    protected function jobs(string $id): JobRepository
    {
        $job = new JobWithParameters('import', 3, 0.5, true, ['chunk' => 100], null, new DateTimeImmutable('2026-01-01'));

        $jobs = Mockery::mock(JobRepository::class);

        $jobs->shouldReceive('findFailed')->with($id)->andReturn((object) [
            'id' => $id,
            'payload' => json_encode([
                'displayName' => JobWithParameters::class,
                'data' => [
                    'commandName' => JobWithParameters::class,
                    'command' => serialize($job),
                ],
            ]),
        ]);

        return $jobs;
    }

    /**
     * Get the job parameter inspector instance.
     */
    protected function inspector(): JobParameterInspector
    {
        return $this->app->make(JobParameterInspector::class);
    }
}
