<?php

namespace Laravel\Horizon\Listeners;

use Illuminate\Support\Carbon;
use Laravel\Horizon\Contracts\IncidentRepository;
use Laravel\Horizon\Events\JobFailed;
use Laravel\Horizon\Events\LongWaitDetected;
use Laravel\Horizon\Events\MasterSupervisorDeployed;

class StoreIncident
{
    public function __construct(
        protected IncidentRepository $incidents,
    ) {
        //
    }

    public function handle(object $event): void
    {
        $incident = match (true) {
            $event instanceof LongWaitDetected => [
                'id' => sprintf('long-wait-%s-%s-%d', $event->connection, $event->queue, Carbon::now()->timestamp),
                'timestamp' => Carbon::now()->timestamp,
                'severity' => 'warning',
                'type' => 'long_wait',
                'title' => sprintf('Long wait on %s', $event->queue),
                'detail' => sprintf('%s connection · %d seconds', $event->connection, $event->seconds),
            ],
            $event instanceof MasterSupervisorDeployed => [
                'id' => sprintf('deployment-%s-%d', $event->master, Carbon::now()->timestamp),
                'timestamp' => Carbon::now()->timestamp,
                'severity' => 'info',
                'type' => 'deployment',
                'title' => 'Horizon supervisors deployed',
                'detail' => (string) $event->master,
            ],
            $event instanceof JobFailed => [
                'id' => sprintf('failure-%s-%d', $event->payload->id(), Carbon::now()->timestamp),
                'timestamp' => Carbon::now()->timestamp,
                'severity' => 'critical',
                'type' => 'job_failed',
                'title' => sprintf('%s failed', $event->payload->displayName() ?? 'Queued job'),
                'detail' => sprintf('Queue %s', $event->queue ?? 'default'),
                'job_id' => (string) $event->payload->id(),
            ],
            default => null,
        };

        if ($incident !== null) {
            $this->incidents->record($incident);
        }
    }
}
