<?php

namespace Laravel\Horizon\Demo;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PingSatellite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        //
    }
}
