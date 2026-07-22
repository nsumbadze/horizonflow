<?php

namespace Laravel\Horizon\Tests\Unit;

use Illuminate\Support\Carbon;
use Laravel\Horizon\Contracts\IncidentRepository;
use Laravel\Horizon\Events\LongWaitDetected;
use Laravel\Horizon\Events\MasterSupervisorDeployed;
use Laravel\Horizon\Listeners\StoreIncident;
use Laravel\Horizon\Tests\UnitTest;

class StoreIncidentTest extends UnitTest
{
    public function test_it_records_long_wait_incidents(): void
    {
        Carbon::setTestNow('2026-07-22 12:00:00');
        $repository = new InMemoryIncidentRepository;

        (new StoreIncident($repository))->handle(new LongWaitDetected('redis', 'emails', 45));

        $this->assertSame('long_wait', $repository->incidents[0]['type']);
        $this->assertSame('Long wait on emails', $repository->incidents[0]['title']);
        $this->assertSame('redis connection · 45 seconds', $repository->incidents[0]['detail']);

        Carbon::setTestNow();
    }

    public function test_it_records_supervisor_deployments(): void
    {
        $repository = new InMemoryIncidentRepository;

        (new StoreIncident($repository))->handle(new MasterSupervisorDeployed('host-1'));

        $this->assertSame('deployment', $repository->incidents[0]['type']);
        $this->assertSame('Horizon supervisors deployed', $repository->incidents[0]['title']);
        $this->assertSame('host-1', $repository->incidents[0]['detail']);
    }
}

class InMemoryIncidentRepository implements IncidentRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $incidents = [];

    public function record(array $incident): void
    {
        $this->incidents[] = $incident;
    }

    public function recent(int $limit = 50): array
    {
        return array_slice($this->incidents, 0, $limit);
    }
}
