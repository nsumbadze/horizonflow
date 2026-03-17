<?php

namespace Laravel\Horizon\Http\Controllers;

use Laravel\Horizon\Contracts\QueueFlowRepository;

class QueueFlowController extends Controller
{
    /**
     * Get queue flow data for the HorizonXBrain visualization.
     *
     * @return array<string, mixed>
     */
    public function index(QueueFlowRepository $flow): array
    {
        return $flow->get();
    }
}
