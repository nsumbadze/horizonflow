<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Tests\IntegrationTest;

/**
 * Guards the path `composer serve` exercises.
 *
 * Dispatch and run cancellation require the controlHorizon gate to be defined,
 * so the shipped example provider has to define it or the demo silently loses
 * both controls.
 */
class WorkbenchDemoPathTest extends IntegrationTest
{
    /**
     * @return array<int, class-string|string>
     */
    protected function getPackageProviders($app)
    {
        return [
            'Laravel\Horizon\HorizonServiceProvider',
            'Workbench\App\Providers\HorizonServiceProvider',
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
    }

    public function test_the_example_provider_defines_the_elevated_control_gate(): void
    {
        $this->assertTrue(Gate::has('controlHorizon'));
        $this->assertTrue(Gate::forUser(null)->check('controlHorizon'));
    }

    public function test_the_demo_can_dispatch_jobs_and_cancel_the_run_they_land_in(): void
    {
        $this->be(new GenericUser(['id' => 1, 'email' => 'horizon@laravel.com']));

        $this->get('/horizon/api/jobs/dispatchable')->assertOk()->assertJsonPath('enabled', true);
        $this->get('/horizon/api/flow/runs')->assertOk()->assertJsonPath('runs', []);

        foreach ([25, 99, 7] as $size) {
            $this->dispatchSprocket('nightly.json', $size)->assertOk();
        }

        // A job belonging to a different run must survive the cancellation.
        $this->dispatchSprocket('weekly.json', 1)->assertOk();

        $this->postJson('/horizon/api/flow/runs/cancel', ['group' => 'demo-assembly:nightly.json'])
            ->assertOk()
            ->assertJsonPath('purged', 3);

        $this->get('/horizon/api/flow/runs')->assertJsonPath('runs.0.group', 'demo-assembly:nightly.json');

        $this->postJson('/horizon/api/flow/runs/release', ['group' => 'demo-assembly:nightly.json'])
            ->assertOk()
            ->assertJsonPath('released', true);

        $this->get('/horizon/api/flow/runs')->assertJsonPath('runs', []);
    }

    protected function dispatchSprocket(string $blueprint, int $batchSize)
    {
        return $this->postJson('/horizon/api/jobs/dispatch', [
            'class' => 'Laravel\Horizon\Demo\AssembleSprocket',
            'parameters' => [
                'blueprint' => $blueprint,
                'batchSize' => $batchSize,
                'notifyOnCompletion' => false,
                'stages' => ['degrease'],
            ],
            'queue' => 'assembly',
        ]);
    }
}
