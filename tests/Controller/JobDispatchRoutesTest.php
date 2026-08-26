<?php

namespace Laravel\Horizon\Tests\Controller;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Tests\ControllerTest;
use Laravel\Horizon\Tests\Feature\Jobs\DispatchableJob;

class JobDispatchRoutesTest extends ControllerTest
{
    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('controlHorizon', fn ($user = null) => true);

        $this->app['config']->set('horizonxflow.dispatch.paths', [dirname(__DIR__).'/Feature/Jobs']);
    }

    public function test_the_dispatchable_route_is_not_swallowed_by_the_job_show_route()
    {
        $response = $this->get('/horizon/api/jobs/dispatchable');

        $response->assertOk();
        $response->assertJsonPath('enabled', true);

        $this->assertContains(
            DispatchableJob::class,
            array_column($response->json('jobs'), 'class')
        );
    }

    public function test_it_reads_the_parameters_of_a_dispatchable_job()
    {
        $response = $this->get('/horizon/api/jobs/dispatchable/parameters?class='.urlencode(DispatchableJob::class));

        $response->assertOk();
        $response->assertJsonPath('dispatchable', true);
        $response->assertJsonPath('parameters.0.name', 'name');
    }

    public function test_it_dispatches_a_job_through_the_route()
    {
        Bus::fake();

        $response = $this->postJson('/horizon/api/jobs/dispatch', [
            'class' => DispatchableJob::class,
            'parameters' => ['name' => 'nightly'],
            'queue' => 'reports',
            'delay' => 30,
        ]);

        $response->assertOk();
        $response->assertJsonPath('dispatched', true);

        Bus::assertDispatched(
            DispatchableJob::class,
            fn (DispatchableJob $job) => $job->name === 'nightly' && $job->queue === 'reports' && $job->delay === 30
        );
    }

    public function test_it_rejects_a_job_class_it_does_not_list()
    {
        $this->postJson('/horizon/api/jobs/dispatch', ['class' => 'App\Jobs\Nope'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The [App\Jobs\Nope] job may not be dispatched from the dashboard.');
    }
}
