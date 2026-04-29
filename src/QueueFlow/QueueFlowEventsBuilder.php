<?php

namespace Laravel\Horizon\QueueFlow;

use Illuminate\Support\Carbon;
use Laravel\Horizon\Contracts\QueueFlowRepository;

class QueueFlowEventsBuilder
{
    public function __construct(
        protected QueueFlowRepository $repository,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?int $since = null, int $limit = 60): array
    {
        $payload = $this->repository->get();
        $events = collect($payload['events'] ?? []);

        if ($since !== null && $since > 0) {
            $events = $events->filter(fn (array $event): bool => (int) ($event['timestamp'] ?? 0) > $since);
        }

        if ($limit > 0) {
            $events = $events->take($limit);
        }

        return [
            'generated_at' => $payload['generated_at'] ?? null,
            'errors' => $payload['errors'] ?? [],
            'since' => $since,
            'now' => Carbon::now()->timestamp,
            'events' => $events->values()->all(),
        ];
    }
}
