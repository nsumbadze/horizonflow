<?php

namespace Laravel\Horizon\Demo;

use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\Horizon\Concerns\InteractsWithCancellation;

class FlashBeacon implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use InteractsWithCancellation;

    /**
     * Create a new demo job instance.
     *
     * @return void
     */
    public function __construct(
        public string $beaconId,
        public string $message,
        public int $priority,
        public DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * The run this job belongs to.
     *
     * Deriving a group from a value the job was queued with is the usual
     * shape: two jobs built from the same beaconId belong to the same run.
     */
    public function cancellationGroup(): ?string
    {
        // Sanitized to the characters a group may contain, rather than with
        // Str::slug, which also strips dots the registry would have accepted.
        $key = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $this->beaconId), '-');

        return $key === '' ? null : 'demo-beacon:'.$key;
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
