<?php

namespace Laravel\Horizon\Demo;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportRecords implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new demo job instance.
     *
     * @param  array<int, string>  $columns
     * @return void
     */
    public function __construct(
        public string $filePath,
        public int $chunkSize,
        public bool $notifyOnCompletion,
        public array $columns,
        public ?string $importedBy = null,
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
