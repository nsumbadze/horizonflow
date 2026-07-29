<?php

namespace Laravel\Horizon\Tests\Feature;

use Laravel\Horizon\Contracts\JobControlRepository;
use Laravel\Horizon\Tests\ControllerTest;
use Mockery;

class JobQueueControlControllerTest extends ControllerTest
{
    public function test_an_authorized_operator_can_pause_a_redis_queue(): void
    {
        $controls = Mockery::mock(JobControlRepository::class);
        $controls->shouldReceive('pauseQueue')
            ->once()
            ->with('redis', 'mail', null)
            ->andReturn([
                'connection' => 'redis',
                'queue' => 'mail',
                'paused_at' => 1_700_000_000,
                'paused_by' => null,
            ]);
        $this->app->instance(JobControlRepository::class, $controls);

        $this->postJson('/horizon/api/flow/queues/pause', [
            'connection' => 'redis',
            'queue' => 'mail',
        ])->assertOk()->assertJson([
            'action' => 'paused',
            'queue' => 'mail',
        ]);
    }

    public function test_an_authorized_operator_can_cancel_a_job(): void
    {
        $controls = Mockery::mock(JobControlRepository::class);
        $controls->shouldReceive('cancel')
            ->once()
            ->with('job-123', null)
            ->andReturn([
                'action' => 'cancelled',
                'id' => 'job-123',
                'status' => 'cancelled',
            ]);
        $this->app->instance(JobControlRepository::class, $controls);

        $this->postJson('/horizon/api/jobs/job-123/cancel')
            ->assertOk()
            ->assertJson(['action' => 'cancelled']);
    }

    public function test_finished_jobs_return_a_conflict(): void
    {
        $controls = Mockery::mock(JobControlRepository::class);
        $controls->shouldReceive('cancel')->once()->andReturn([
            'action' => 'conflict',
            'status' => 'completed',
        ]);
        $this->app->instance(JobControlRepository::class, $controls);

        $this->postJson('/horizon/api/jobs/job-123/cancel')
            ->assertStatus(409);
    }

    public function test_queue_controls_require_a_connection_and_queue(): void
    {
        $this->postJson('/horizon/api/flow/queues/pause')
            ->assertStatus(422);
    }
}
