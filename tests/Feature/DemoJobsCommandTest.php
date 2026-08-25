<?php

namespace Laravel\Horizon\Tests\Feature;

use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Tests\IntegrationTest;

class DemoJobsCommandTest extends IntegrationTest
{
    public function test_demo_jobs_are_seeded_in_every_state()
    {
        $this->artisan('horizonxflow:demo-jobs');

        $jobs = $this->app->make(JobRepository::class);

        $this->assertGreaterThan(0, $jobs->countPending());
        $this->assertGreaterThan(0, $jobs->countCompleted());
        $this->assertGreaterThan(0, $jobs->countSilenced());
        $this->assertGreaterThan(0, $jobs->countFailed());
    }

    public function test_demo_jobs_keep_the_payload_tags_of_their_definition()
    {
        $this->artisan('horizonxflow:demo-jobs');

        $tags = $this->app->make(JobRepository::class)->getPending()
            ->map(fn ($job) => json_decode($job->payload, true)['tags'] ?? [])
            ->all();

        $this->assertContains(['sprocket:9330', 'blueprint:mk4'], $tags);
        $this->assertContains(['owner' => 'nightshift@acme.test', 'webhook' => 'inventory'], $tags);
    }

    public function test_demo_jobs_can_be_cleared()
    {
        $this->artisan('horizonxflow:demo-jobs');
        $this->artisan('horizonxflow:demo-jobs', ['--clear' => true]);

        $jobs = $this->app->make(JobRepository::class);

        $this->assertSame(0, $jobs->countRecent());
        $this->assertSame(0, $jobs->countPending());
        $this->assertSame(0, $jobs->countCompleted());
        $this->assertSame(0, $jobs->countSilenced());
        $this->assertSame(0, $jobs->countFailed());
    }
}
