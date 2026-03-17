<?php

namespace Laravel\Horizon\Repositories;

use Illuminate\Support\Carbon;
use Laravel\Horizon\Contracts\QueueFlowRepository;

class MockQueueFlowRepository implements QueueFlowRepository
{
    /**
     * Get mock queue flow data for early UI development.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $tick = Carbon::now()->second;
        $pending = 180 + ($tick * 3);
        $failed = 7 + ($tick % 5);

        return [
            'source' => 'mock',
            'generated_at' => Carbon::now()->toJSON(),
            'summary' => [
                'pending' => $pending,
                'processing' => 24 + ($tick % 8),
                'completed' => 12840 + ($tick * 11),
                'failed' => $failed,
                'delayed' => 36 + ($tick % 12),
                'throughput_per_minute' => 740 + ($tick * 2),
                'average_wait_seconds' => 9 + ($tick % 7),
                'connections' => ['redis', 'mysql', 'pgsql'],
            ],
            'nodes' => [
                $this->node('producer-app', 'producer', 'Application Events', 'healthy'),
                $this->node('queue-default', 'queue', 'default', 'healthy', ['pending' => 68, 'wait' => 3]),
                $this->node('queue-mail', 'queue', 'mail', 'warning', ['pending' => 126, 'wait' => 18]),
                $this->node('queue-imports', 'queue', 'imports', 'critical', ['pending' => 249, 'wait' => 43]),
                $this->node('workers-primary', 'worker', 'primary workers', 'healthy', ['processes' => 18]),
                $this->node('workers-bulk', 'worker', 'bulk workers', 'warning', ['processes' => 6]),
                $this->node('completed', 'result', 'completed', 'healthy'),
                $this->node('failed', 'result', 'failed', 'critical', ['failed' => $failed]),
                $this->node('delayed', 'result', 'delayed', 'warning'),
            ],
            'edges' => [
                $this->edge('producer-app', 'queue-default', 'healthy', 'dispatch', 260),
                $this->edge('producer-app', 'queue-mail', 'warning', 'notifications', 148),
                $this->edge('producer-app', 'queue-imports', 'critical', 'bulk imports', 84),
                $this->edge('queue-default', 'workers-primary', 'healthy', 'reserve', 232),
                $this->edge('queue-mail', 'workers-primary', 'warning', 'reserve', 91),
                $this->edge('queue-imports', 'workers-bulk', 'critical', 'reserve', 38),
                $this->edge('workers-primary', 'completed', 'healthy', 'finish', 310),
                $this->edge('workers-bulk', 'completed', 'warning', 'finish', 41),
                $this->edge('workers-bulk', 'failed', 'critical', 'exception', 6),
                $this->edge('queue-mail', 'delayed', 'warning', 'release', 22),
            ],
            'queues' => [
                $this->queue('redis', 'default', 68, 3, 16, 232),
                $this->queue('mysql', 'mail', 126, 18, 8, 91),
                $this->queue('pgsql', 'imports', 249, 43, 6, 38),
            ],
            'events' => [
                ['status' => 'healthy', 'label' => 'App\\Jobs\\SendWebhook completed in 184ms'],
                ['status' => 'warning', 'label' => 'App\\Jobs\\SendEmail released for retry'],
                ['status' => 'critical', 'label' => 'App\\Jobs\\ImportRecords failed after timeout'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function node(string $id, string $type, string $label, string $status, array $metrics = []): array
    {
        return compact('id', 'type', 'label', 'status', 'metrics');
    }

    /**
     * @return array<string, mixed>
     */
    protected function edge(string $source, string $target, string $status, string $label, int $rate): array
    {
        return [
            'id' => "{$source}-{$target}",
            'source' => $source,
            'target' => $target,
            'status' => $status,
            'label' => $label,
            'rate_per_minute' => $rate,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function queue(string $connection, string $name, int $pending, int $wait, int $processes, int $throughput): array
    {
        return [
            'connection' => $connection,
            'name' => $name,
            'pending' => $pending,
            'wait_seconds' => $wait,
            'processes' => $processes,
            'throughput_per_minute' => $throughput,
            'driver' => $connection,
        ];
    }
}
