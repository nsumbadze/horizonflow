<?php

namespace Laravel\Horizon\Tests\Feature\Jobs;

use DateTimeImmutable;
use Illuminate\Queue\InteractsWithQueue;

class JobWithParameters
{
    use InteractsWithQueue;

    /**
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $options
     * @return void
     */
    public function __construct(
        public string $name,
        public int $attempts,
        public float $ratio,
        public bool $notify,
        public array $options,
        public ?string $reason = null,
        public ?DateTimeImmutable $scheduledFor = null,
    ) {
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (isset($_SERVER['horizon.fail'])) {
            return $this->fail();
        }

        $_SERVER['horizon.job.parameters'] = [
            'name' => $this->name,
            'attempts' => $this->attempts,
            'ratio' => $this->ratio,
            'notify' => $this->notify,
            'options' => $this->options,
            'reason' => $this->reason,
        ];
    }
}
