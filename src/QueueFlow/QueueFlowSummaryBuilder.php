<?php

namespace Laravel\Horizon\QueueFlow;

use Laravel\Horizon\Contracts\QueueFlowRepository;

class QueueFlowSummaryBuilder
{
    public function __construct(
        protected QueueFlowRepository $repository,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $payload = $this->repository->get();

        return [
            'source' => $payload['source'] ?? null,
            'sources' => $payload['sources'] ?? [],
            'errors' => $payload['errors'] ?? [],
            'health' => $payload['health'] ?? [],
            'meta' => $payload['meta'] ?? [],
            'generated_at' => $payload['generated_at'] ?? null,
            'summary' => $payload['summary'] ?? [],
        ];
    }
}
