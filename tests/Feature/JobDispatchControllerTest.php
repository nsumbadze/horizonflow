<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Laravel\Horizon\Http\Controllers\JobDispatchController;
use Laravel\Horizon\JobDispatchRegistry;
use Laravel\Horizon\JobParameterInspector;
use Laravel\Horizon\Tests\Feature\Jobs\BasicJob;
use Laravel\Horizon\Tests\Feature\Jobs\DispatchableJob;
use Laravel\Horizon\Tests\Feature\Jobs\SelfRoutingJob;
use Laravel\Horizon\Tests\Feature\Jobs\UndispatchableJob;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class JobDispatchControllerTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return ['Laravel\Horizon\HorizonServiceProvider'];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('horizonxflow.dispatch.paths', [__DIR__.'/Jobs']);
        $app['config']->set('queue.default', 'redis');
        $app['config']->set('queue.connections', [
            'redis' => ['driver' => 'redis', 'queue' => 'default'],
            'reports' => ['driver' => 'redis', 'queue' => 'reports'],
        ]);
    }

    public function test_it_lists_the_dispatchable_jobs_and_connections(): void
    {
        $response = $this->controller()->index($this->registry(), $this->app['config']);

        $this->assertTrue($response['enabled']);
        $this->assertSame('redis', $response['default_connection']);
        $this->assertSame(86400, $response['max_delay']);
        $this->assertContains(DispatchableJob::class, array_column($response['jobs'], 'class'));
        $this->assertSame(
            [['name' => 'redis', 'driver' => 'redis', 'queue' => 'default'], ['name' => 'reports', 'driver' => 'redis', 'queue' => 'reports']],
            $response['connections']
        );
    }

    public function test_it_reports_that_dispatching_is_disabled(): void
    {
        config()->set('horizonxflow.dispatch.enabled', false);

        $response = $this->controller()->index($this->registry(), $this->app['config']);

        $this->assertFalse($response['enabled']);
        $this->assertSame([], $response['jobs']);
    }

    public function test_it_describes_the_parameters_of_a_dispatchable_job(): void
    {
        $response = $this->controller()->parameters(
            $this->request(['class' => DispatchableJob::class]),
            $this->registry(),
            $this->inspector()
        );

        $this->assertTrue($response['dispatchable']);
        $this->assertSame(['name', 'attempts', 'reason', 'options'], array_column($response['parameters'], 'name'));
        $this->assertTrue($response['parameters'][0]['required']);
        $this->assertFalse($response['parameters'][1]['required']);
    }

    public function test_it_reports_a_job_whose_parameters_may_not_be_supplied(): void
    {
        $response = $this->controller()->parameters(
            $this->request(['class' => UndispatchableJob::class]),
            $this->registry(),
            $this->inspector()
        );

        $this->assertFalse($response['dispatchable']);
        $this->assertStringContainsString('[runAt] parameter is required', $response['reason']);
    }

    public function test_it_dispatches_a_job_with_the_requested_options(): void
    {
        Bus::fake();

        $response = $this->store([
            'class' => DispatchableJob::class,
            'parameters' => ['name' => 'nightly', 'attempts' => '7', 'options' => ['dry' => true]],
            'connection' => 'reports',
            'queue' => 'nightly-reports',
            'delay' => 90,
        ]);

        $this->assertSame(
            ['dispatched' => true, 'class' => DispatchableJob::class, 'connection' => 'reports', 'queue' => 'nightly-reports', 'delay' => 90],
            $response
        );

        Bus::assertDispatched(DispatchableJob::class, function (DispatchableJob $job) {
            return $job->name === 'nightly'
                && $job->attempts === 7
                && $job->reason === null
                && $job->options === ['dry' => true]
                && $job->connection === 'reports'
                && $job->queue === 'nightly-reports'
                && $job->delay === 90;
        });
    }

    public function test_it_dispatches_a_job_without_any_options(): void
    {
        Bus::fake();

        $response = $this->store(['class' => DispatchableJob::class, 'parameters' => ['name' => 'now']]);

        $this->assertSame(['connection' => null, 'queue' => null, 'delay' => 0], array_intersect_key($response, array_flip(['connection', 'queue', 'delay'])));

        Bus::assertDispatched(DispatchableJob::class, function (DispatchableJob $job) {
            return $job->name === 'now'
                && $job->attempts === 3
                && $job->connection === null
                && $job->queue === null
                && $job->delay === null;
        });
    }

    public function test_it_reports_the_queue_a_job_chooses_for_itself(): void
    {
        Bus::fake();

        $response = $this->store(['class' => SelfRoutingJob::class, 'parameters' => ['reference' => 'abc']]);

        $this->assertSame('self-routed', $response['queue']);

        Bus::assertDispatched(SelfRoutingJob::class, fn (SelfRoutingJob $job) => $job->queue === 'self-routed');
    }

    public function test_a_requested_queue_overrides_the_one_a_job_chooses(): void
    {
        Bus::fake();

        $response = $this->store([
            'class' => SelfRoutingJob::class,
            'parameters' => ['reference' => 'abc'],
            'queue' => 'operator-choice',
        ]);

        $this->assertSame('operator-choice', $response['queue']);

        Bus::assertDispatched(SelfRoutingJob::class, fn (SelfRoutingJob $job) => $job->queue === 'operator-choice');
    }

    public function test_it_rejects_a_class_that_may_not_be_dispatched(): void
    {
        $this->assertRejects(['class' => BasicJob::class], 'may not be dispatched from the dashboard');
    }

    public function test_it_rejects_a_job_whose_parameters_may_not_be_supplied(): void
    {
        $this->assertRejects(['class' => UndispatchableJob::class], '[runAt] parameter is required');
    }

    public function test_it_rejects_a_missing_required_parameter(): void
    {
        $this->assertRejects(['class' => DispatchableJob::class, 'parameters' => []], 'The [name] parameter is required.');
    }

    public function test_it_rejects_a_parameter_the_job_does_not_accept(): void
    {
        $this->assertRejects(
            ['class' => DispatchableJob::class, 'parameters' => ['name' => 'a', 'nope' => 1]],
            'does not accept a [nope] parameter'
        );
    }

    public function test_it_rejects_a_parameter_of_the_wrong_type(): void
    {
        $this->assertRejects(
            ['class' => DispatchableJob::class, 'parameters' => ['name' => 'a', 'attempts' => 'many']],
            'The [attempts] parameter must be numeric.'
        );
    }

    public function test_it_rejects_parameters_that_are_not_an_object(): void
    {
        $this->assertRejects(
            ['class' => DispatchableJob::class, 'parameters' => 'name=a'],
            'The job parameters must be given as an object.'
        );
    }

    public function test_it_rejects_a_connection_that_is_not_configured(): void
    {
        $this->assertRejects(
            ['class' => DispatchableJob::class, 'parameters' => ['name' => 'a'], 'connection' => 'sqs'],
            'The [sqs] queue connection is not configured.'
        );
    }

    public function test_it_rejects_a_queue_name_with_unexpected_characters(): void
    {
        $this->assertRejects(
            ['class' => DispatchableJob::class, 'parameters' => ['name' => 'a'], 'queue' => 'reports/*'],
            'The queue name may only contain'
        );
    }

    public function test_it_rejects_a_delay_beyond_the_configured_maximum(): void
    {
        config()->set('horizonxflow.dispatch.max_delay', 600);

        $this->assertRejects(
            ['class' => DispatchableJob::class, 'parameters' => ['name' => 'a'], 'delay' => 900],
            'The delay must be between 0 and 600 seconds.'
        );
    }

    public function test_it_rejects_a_delay_that_is_not_a_whole_number(): void
    {
        $this->assertRejects(
            ['class' => DispatchableJob::class, 'parameters' => ['name' => 'a'], 'delay' => '2.5'],
            'The delay must be given as a whole number of seconds.'
        );
    }

    public function test_it_does_not_dispatch_a_job_it_rejects(): void
    {
        Bus::fake();

        $this->assertRejects(
            ['class' => DispatchableJob::class, 'parameters' => ['name' => 'a'], 'connection' => 'sqs'],
            'is not configured'
        );

        Bus::assertNothingDispatched();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function store(array $input): array
    {
        return $this->controller()->store(
            $this->request($input),
            $this->registry(),
            $this->inspector(),
            $this->app['config']
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function assertRejects(array $input, string $message): void
    {
        try {
            $this->store($input);
            $this->fail('Expected HttpException with status 422.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function request(array $input): Request
    {
        return Request::create('/', 'POST', $input);
    }

    protected function controller(): JobDispatchController
    {
        return new JobDispatchController();
    }

    protected function registry(): JobDispatchRegistry
    {
        return new JobDispatchRegistry($this->app, $this->app['config']);
    }

    protected function inspector(): JobParameterInspector
    {
        return new JobParameterInspector($this->app);
    }
}
