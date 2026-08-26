<?php

namespace Laravel\Horizon\Tests\Controller;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Tests\ControllerTest;
use Laravel\Horizon\Tests\Feature\Jobs\BasicJob;
use Laravel\Horizon\Tests\Feature\Jobs\ChainedRunJob;

class RunControlRoutesTest extends ControllerTest
{
    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('controlHorizon', fn ($user = null) => true);
    }

    public function test_it_lists_nothing_when_no_run_is_cancelled()
    {
        $this->get('/horizon/api/flow/runs')
            ->assertOk()
            ->assertJsonPath('runs', []);
    }

    public function test_it_cancels_and_lists_a_run_by_group()
    {
        Queue::push(new ChainedRunJob(2));

        $this->postJson('/horizon/api/flow/runs/cancel', ['group' => 'test-run:2'])
            ->assertOk()
            ->assertJsonPath('action', 'cancelled')
            ->assertJsonPath('group', 'test-run:2')
            ->assertJsonPath('purged', 1);

        $this->get('/horizon/api/flow/runs')
            ->assertOk()
            ->assertJsonPath('runs.0.group', 'test-run:2');
    }

    public function test_it_cancels_the_run_a_job_belongs_to()
    {
        $id = Queue::push(new ChainedRunJob(4));

        $this->postJson('/horizon/api/flow/runs/cancel', ['job' => $id])
            ->assertOk()
            ->assertJsonPath('group', 'test-run:4');
    }

    public function test_it_lifts_a_cancelled_run()
    {
        $this->postJson('/horizon/api/flow/runs/cancel', ['group' => 'test-run:2'])->assertOk();

        $this->postJson('/horizon/api/flow/runs/release', ['group' => 'test-run:2'])
            ->assertOk()
            ->assertJsonPath('released', true);

        $this->get('/horizon/api/flow/runs')->assertJsonPath('runs', []);
    }

    public function test_it_reports_the_run_a_job_belongs_to()
    {
        $id = Queue::push(new ChainedRunJob(3));

        $this->get("/horizon/api/jobs/{$id}/run")
            ->assertOk()
            ->assertJsonPath('group', 'test-run:3')
            ->assertJsonPath('run', null);
    }

    public function test_it_reports_no_run_for_a_job_that_declares_none()
    {
        $id = Queue::push(new BasicJob);

        $this->get("/horizon/api/jobs/{$id}/run")
            ->assertOk()
            ->assertJsonPath('group', null);
    }

    public function test_it_refuses_to_cancel_the_run_of_a_job_that_declares_none()
    {
        $id = Queue::push(new BasicJob);

        $this->postJson('/horizon/api/flow/runs/cancel', ['job' => $id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That job does not declare a run, so there is no run to cancel. Add a cancellationGroup() method to the job.');
    }

    public function test_it_refuses_a_request_that_names_neither_a_run_nor_a_job()
    {
        $this->postJson('/horizon/api/flow/runs/cancel', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A run group or a job that belongs to the run is required.');
    }

    public function test_it_refuses_a_group_that_could_address_other_keys()
    {
        $this->postJson('/horizon/api/flow/runs/cancel', ['group' => 'test run/2'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The run group may only contain letters, numbers, dashes, underscores, dots, and colons.');
    }

    public function test_it_refuses_a_lifetime_outside_the_allowed_range()
    {
        $this->postJson('/horizon/api/flow/runs/cancel', ['group' => 'test-run:2', 'ttl' => 5])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The lifetime must be between 60 and 604800 seconds.');
    }

    public function test_it_refuses_an_unknown_job()
    {
        $this->postJson('/horizon/api/flow/runs/cancel', ['job' => 'missing-job-id'])
            ->assertStatus(404);
    }
}
