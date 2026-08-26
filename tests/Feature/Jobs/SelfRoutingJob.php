<?php

namespace Laravel\Horizon\Tests\Feature\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SelfRoutingJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * Create a new job instance that picks its own queue.
     *
     * @return void
     */
    public function __construct(public string $reference)
    {
        $this->onQueue('self-routed');
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
