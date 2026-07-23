<?php

namespace Laravel\Horizon\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Horizon\Contracts\IncidentRepository;

class IncidentController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    public function index(Request $request, IncidentRepository $incidents): array
    {
        $limit = max(1, min((int) $request->query('limit', 50), 100));

        return ['incidents' => $incidents->recent($limit)];
    }
}
