<?php

namespace Laravel\Horizon\Repositories;

trait BuildsQueueFlowMetadata
{
    /**
     * Get application metadata for queue flow clients.
     *
     * @return array<string, mixed>
     */
    protected function metadata(): array
    {
        return [
            'app_name' => config('app.name', 'Laravel'),
            'horizon_name' => config('horizon.name'),
            'environment' => app()->environment(),
            'path' => config('horizon.path', 'horizon'),
        ];
    }
}
