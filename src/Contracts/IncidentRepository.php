<?php

namespace Laravel\Horizon\Contracts;

interface IncidentRepository
{
    /**
     * @param  array<string, mixed>  $incident
     */
    public function record(array $incident): void;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 50): array;
}
