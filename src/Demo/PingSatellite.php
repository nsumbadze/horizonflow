<?php

namespace Laravel\Horizon\Demo;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\Horizon\Concerns\InteractsWithCancellation;

class PingSatellite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use InteractsWithCancellation;

    /**
     * Create a new demo job instance.
     *
     * @param  array<string, mixed>  $payload
     * @return void
     */
    public function __construct(
        public string $endpoint,
        public array $payload,
        public float $timeoutSeconds,
        public bool $verifySsl = true,
    ) {
    }

    /**
     * The run this job belongs to.
     *
     * Deriving a group from a value the job was queued with is the usual
     * shape: two jobs built from the same endpoint belong to the same run.
     */
    public function cancellationGroup(): ?string
    {
        // Sanitized to the characters a group may contain, rather than with
        // Str::slug, which also strips dots the registry would have accepted.
        $key = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $this->endpoint), '-');

        return $key === '' ? null : 'demo-ping:'.$key;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        //
    }
}
