<?php

namespace Laravel\Horizon\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Demo\DeliverWebhook;
use Laravel\Horizon\Demo\ImportRecords;
use Laravel\Horizon\Demo\PushDeviceMessage;
use Laravel\Horizon\JobPayload;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'horizonxflow:demo-jobs')]
class DemoJobsCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'horizonxflow:demo-jobs {--clear : Delete the previously seeded demo jobs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed failed demo jobs so the dashboard can be explored without a running queue';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(JobRepository $jobs)
    {
        if ($this->option('clear')) {
            return $this->clear($jobs);
        }

        foreach ($this->demoJobs() as [$job, $queue, $message]) {
            $payload = $this->payloadFor($job);

            $jobs->pushed('redis', $queue, $payload);
            $jobs->failed($this->exceptionFor($message), 'redis', $queue, $payload);

            $this->components->info('Seeded failed demo job: '.get_class($job));
        }

        $this->components->info('Open the failed jobs screen and retry one with edited parameters.');

        return 0;
    }

    /**
     * Delete every previously seeded demo job.
     *
     * @return int
     */
    protected function clear(JobRepository $jobs)
    {
        $deleted = 0;

        foreach ($jobs->getFailed() as $job) {
            if (! Str::startsWith(json_decode($job->payload, true)['data']['commandName'] ?? '', 'Laravel\\Horizon\\Demo\\')) {
                continue;
            }

            $jobs->deleteFailed($job->id);

            $deleted++;
        }

        $this->components->info($deleted.' demo jobs deleted.');

        return 0;
    }

    /**
     * Get the demo jobs that should be seeded as failures.
     *
     * @return array<int, array{0: object, 1: string, 2: string}>
     */
    protected function demoJobs()
    {
        return [
            [
                new ImportRecords(
                    'imports/2026-07-customers.csv',
                    500,
                    true,
                    ['email', 'first_name', 'last_name', 'country'],
                    'ops@example.com',
                ),
                'imports',
                'Illuminate\\Queue\\MaxAttemptsExceededException: App\\Jobs\\ImportRecords has been attempted too many times',
            ],
            [
                new PushDeviceMessage(
                    'f4c1c0d1e2a34b5c9d8e7f6a5b4c3d2e',
                    'Your order has shipped.',
                    5,
                    new DateTimeImmutable('2026-08-01 09:00:00'),
                ),
                'notifications',
                'GuzzleHttp\\Exception\\ConnectException: cURL error 28: Operation timed out after 10000 milliseconds',
            ],
            [
                new DeliverWebhook(
                    'https://example.test/hooks/orders',
                    ['event' => 'order.created', 'order_id' => 8412],
                    2.5,
                    true,
                ),
                'webhooks',
                'Symfony\\Component\\HttpClient\\Exception\\ServerException: HTTP 503 returned for "https://example.test/hooks/orders"',
            ],
        ];
    }

    /**
     * Build a queue payload for the given demo job.
     *
     * @param  object  $job
     * @return \Laravel\Horizon\JobPayload
     */
    protected function payloadFor($job)
    {
        $id = (string) Str::uuid();

        $payload = new JobPayload(json_encode([
            'uuid' => $id,
            'id' => $id,
            'displayName' => get_class($job),
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => 3,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => 60,
            'retryUntil' => null,
            'attempts' => 3,
            'data' => [
                'commandName' => get_class($job),
                'command' => serialize($job),
            ],
        ]));

        return $payload->prepare($job);
    }

    /**
     * Build a throwable carrying the given failure message.
     *
     * @param  string  $message
     * @return \Throwable
     */
    protected function exceptionFor($message)
    {
        try {
            throw new RuntimeException($message);
        } catch (Throwable $e) {
            return $e;
        }
    }
}
