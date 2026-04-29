<?php

namespace Laravel\Horizon\Tests\Feature;

use Laravel\Horizon\Contracts\QueueFlowRepository;
use Laravel\Horizon\QueueFlow\QueueFlowEventsBuilder;
use Laravel\Horizon\QueueFlow\QueueFlowGraphBuilder;
use Laravel\Horizon\QueueFlow\QueueFlowQueueJobsBuilder;
use Laravel\Horizon\QueueFlow\QueueFlowQueuesBuilder;
use Laravel\Horizon\QueueFlow\QueueFlowSummaryBuilder;
use Mockery;
use Orchestra\Testbench\TestCase;

class QueueFlowBuildersTest extends TestCase
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

    public function test_summary_builder_projects_only_summary_section(): void
    {
        $payload = (new QueueFlowSummaryBuilder($this->repository()))->build();

        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayNotHasKey('queues', $payload);
        $this->assertArrayNotHasKey('nodes', $payload);
        $this->assertSame(180, $payload['summary']['pending']);
    }

    public function test_graph_builder_returns_nodes_and_edges(): void
    {
        $payload = (new QueueFlowGraphBuilder($this->repository()))->build();

        $this->assertCount(2, $payload['nodes']);
        $this->assertCount(1, $payload['edges']);
        $this->assertArrayNotHasKey('queues', $payload);
    }

    public function test_queues_builder_strips_per_queue_job_arrays(): void
    {
        $payload = (new QueueFlowQueuesBuilder($this->repository()))->build();

        $this->assertSame(2, $payload['total']);
        $this->assertSame(2, $payload['returned']);
        $this->assertArrayNotHasKey('jobs', $payload['queues'][0]);
        $this->assertArrayNotHasKey('job_classes', $payload['queues'][0]);
    }

    public function test_queues_builder_filters_by_name(): void
    {
        $payload = (new QueueFlowQueuesBuilder($this->repository()))->build(filter: 'mail');

        $this->assertSame(2, $payload['total']);
        $this->assertSame(1, $payload['returned']);
        $this->assertSame('mail', $payload['queues'][0]['name']);
    }

    public function test_queues_builder_sorts_descending_by_pending(): void
    {
        $payload = (new QueueFlowQueuesBuilder($this->repository()))->build(sort: 'pending', direction: 'desc');

        $this->assertSame('default', $payload['queues'][0]['name']);
        $this->assertSame(120, $payload['queues'][0]['pending']);
    }

    public function test_queue_jobs_builder_returns_jobs_for_matching_queue(): void
    {
        $payload = (new QueueFlowQueueJobsBuilder($this->repository()))->build('redis:redis:mail');

        $this->assertNotNull($payload);
        $this->assertSame('mail', $payload['queue']['name']);
        $this->assertCount(1, $payload['jobs']);
        $this->assertSame('App\\Jobs\\Send', $payload['jobs'][0]['name']);
    }

    public function test_queue_jobs_builder_returns_null_when_queue_missing(): void
    {
        $this->assertNull((new QueueFlowQueueJobsBuilder($this->repository()))->build('redis:redis:nope'));
    }

    public function test_events_builder_filters_by_since_timestamp(): void
    {
        $payload = (new QueueFlowEventsBuilder($this->repository()))->build(since: 1000);

        $this->assertCount(1, $payload['events']);
        $this->assertSame(1001, $payload['events'][0]['timestamp']);
        $this->assertSame(1000, $payload['since']);
        $this->assertIsInt($payload['now']);
    }

    public function test_events_builder_caps_limit(): void
    {
        $payload = (new QueueFlowEventsBuilder($this->repository()))->build(limit: 1);

        $this->assertCount(1, $payload['events']);
    }

    protected function repository(): QueueFlowRepository
    {
        $repository = Mockery::mock(QueueFlowRepository::class);
        $repository->shouldReceive('get')->andReturn($this->fixture());

        return $repository;
    }

    /**
     * @return array<string, mixed>
     */
    protected function fixture(): array
    {
        return [
            'source' => 'redis',
            'sources' => ['redis'],
            'errors' => [],
            'meta' => ['app_name' => 'TestApp'],
            'generated_at' => '2024-01-01T00:00:00+00:00',
            'summary' => [
                'pending' => 180,
                'failed' => 0,
                'throughput_per_minute' => 740,
            ],
            'nodes' => [
                ['id' => 'producer-app', 'type' => 'producer'],
                ['id' => 'queue-default', 'type' => 'queue'],
            ],
            'edges' => [
                ['id' => 'producer-app-queue-default', 'source' => 'producer-app', 'target' => 'queue-default'],
            ],
            'queues' => [
                [
                    'name' => 'default',
                    'connection' => 'redis',
                    'driver' => 'redis',
                    'source' => 'redis',
                    'pending' => 120,
                    'failed' => 0,
                    'jobs' => [['id' => 'a', 'name' => 'App\\Jobs\\A']],
                    'job_classes' => [['name' => 'App\\Jobs\\A', 'pending' => 1]],
                ],
                [
                    'name' => 'mail',
                    'connection' => 'redis',
                    'driver' => 'redis',
                    'source' => 'redis',
                    'pending' => 60,
                    'failed' => 1,
                    'jobs' => [['id' => 'b', 'name' => 'App\\Jobs\\Send']],
                    'job_classes' => [['name' => 'App\\Jobs\\Send', 'pending' => 1]],
                ],
            ],
            'events' => [
                ['label' => 'old', 'timestamp' => 999],
                ['label' => 'new', 'timestamp' => 1001],
            ],
        ];
    }
}
