<?php

namespace Laravel\Horizon\Contracts;

interface QueueFlowRepository
{
    /**
     * Get queue flow data for the HorizonXBrain visualization.
     *
     * @return array<string, mixed>
     */
    public function get(): array;
}
