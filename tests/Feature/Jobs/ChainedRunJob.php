<?php

namespace Laravel\Horizon\Tests\Feature\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Laravel\Horizon\Concerns\InteractsWithCancellation;

class ChainedRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithCancellation;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(public int $runId, public int $page = 1)
    {
    }

    /**
     * The run this job belongs to.
     */
    public function cancellationGroup(): ?string
    {
        return "test-run:{$this->runId}";
    }

    /**
     * Execute the job, chaining the next page behind it.
     *
     * @return void
     */
    public function handle()
    {
        $_SERVER['horizon.run.pages'][] = $this->page;

        if ($this->cancelIfRequested()) {
            return;
        }

        if ($this->page < 5) {
            self::dispatch($this->runId, $this->page + 1);
        }
    }
}
