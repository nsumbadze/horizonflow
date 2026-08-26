<?php

namespace Laravel\Horizon\Tests\Feature\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class DispatchableJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $options
     * @return void
     */
    public function __construct(
        public string $name,
        public int $attempts = 3,
        public ?string $reason = null,
        public array $options = [],
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
