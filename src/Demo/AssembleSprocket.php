<?php

namespace Laravel\Horizon\Demo;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AssembleSprocket implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new demo job instance.
     *
     * @param  array<int, string>  $stages
     * @return void
     */
    public function __construct(
        public string $blueprint,
        public int $batchSize,
        public bool $notifyOnCompletion,
        public array $stages,
        public ?string $requestedBy = null,
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
