<?php

namespace Laravel\Horizon\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Demo\AssembleSprocket;
use Laravel\Horizon\Demo\FlashBeacon;
use Laravel\Horizon\Demo\PingSatellite;
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
    protected $description = 'Seed pending, completed, silenced, and failed demo jobs so the dashboard can be explored without a running queue';

    /**
     * The job lists a seeded demo job can be referenced from.
     *
     * @var array<int, string>
     */
    protected const LISTS = [
        'recent_jobs', 'pending_jobs', 'completed_jobs',
        'silenced_jobs', 'failed_jobs', 'recent_failed_jobs',
    ];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(JobRepository $jobs, RedisFactory $redis)
    {
        if ($this->option('clear')) {
            return $this->clear($jobs, $redis);
        }

        foreach ($this->demoJobs() as $demo) {
            $this->seed($jobs, $demo, $this->payloadFor($demo['job'], $demo['tags'] ?? null));

            $this->components->info(sprintf(
                'Seeded %s demo job: %s', $demo['state'], get_class($demo['job'])
            ));
        }

        $this->components->info('Open the pending, completed, silenced, and failed job screens to explore them.');

        return 0;
    }

    /**
     * Store the given demo job in the state it should be presented in.
     *
     * @param  array<string, mixed>  $demo
     * @return void
     */
    protected function seed(JobRepository $jobs, array $demo, JobPayload $payload)
    {
        $jobs->pushed('redis', $demo['queue'], $payload);

        if ($demo['state'] === 'pending') {
            return;
        }

        $jobs->reserved('redis', $demo['queue'], $payload);

        if ($demo['state'] === 'failed') {
            $jobs->failed($this->exceptionFor($demo['error']), 'redis', $demo['queue'], $payload);

            return;
        }

        $jobs->completed($payload, false, $demo['state'] === 'silenced');
    }

    /**
     * Delete every previously seeded demo job.
     *
     * @return int
     */
    protected function clear(JobRepository $jobs, RedisFactory $redis)
    {
        $ids = Collection::make([
            $jobs->getRecent(), $jobs->getPending(), $jobs->getCompleted(),
            $jobs->getSilenced(), $jobs->getFailed(),
        ])->flatten(1)->filter(function ($job) {
            return $this->isDemoJob($job);
        })->pluck('id')->unique();

        $connection = $redis->connection('horizon');

        foreach ($ids as $id) {
            foreach (self::LISTS as $list) {
                $connection->zrem($list, $id);
            }

            $connection->del($id);
        }

        $this->components->info($ids->count().' demo jobs deleted.');

        return 0;
    }

    /**
     * Determine whether the given job was seeded by this command.
     *
     * @param  object  $job
     * @return bool
     */
    protected function isDemoJob($job)
    {
        $payload = json_decode($job->payload ?? '', true);

        return Str::startsWith($payload['data']['commandName'] ?? '', 'Laravel\\Horizon\\Demo\\');
    }

    /**
     * Get the demo jobs that should be seeded.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function demoJobs()
    {
        return [
            [
                'state' => 'failed',
                'queue' => 'assembly',
                'job' => new AssembleSprocket(
                    'blueprints/sprocket-mk3.json',
                    500,
                    true,
                    ['degrease', 'align', 'torque', 'inspect'],
                    'workshop@acme.test',
                ),
                'tags' => ['sprocket:8412', 'blueprint:mk3'],
                'error' => 'Illuminate\\Queue\\MaxAttemptsExceededException: Acme\\Jobs\\AssembleSprocket has been attempted too many times',
            ],
            [
                'state' => 'failed',
                'queue' => 'notifications',
                'job' => new FlashBeacon(
                    'f4c1c0d1e2a34b5c9d8e7f6a5b4c3d2e',
                    'Sprocket assembly finished.',
                    5,
                    new DateTimeImmutable('2026-08-01 09:00:00'),
                ),
                'tags' => ['beacon:f4c1c0d1', 'channel:push'],
                'error' => 'GuzzleHttp\\Exception\\ConnectException: cURL error 28: Operation timed out after 10000 milliseconds',
            ],
            [
                'state' => 'failed',
                'queue' => 'webhooks',
                'job' => new PingSatellite(
                    'https://acme.test/hooks/telemetry',
                    ['event' => 'sprocket.assembled', 'sprocket_id' => 8412],
                    2.5,
                    true,
                ),
                'tags' => ['webhook:telemetry'],
                'error' => 'Symfony\\Component\\HttpClient\\Exception\\ServerException: HTTP 503 returned for "https://acme.test/hooks/telemetry"',
            ],
            [
                'state' => 'pending',
                'queue' => 'assembly',
                'job' => new AssembleSprocket(
                    'blueprints/sprocket-mk4.json',
                    120,
                    false,
                    ['degrease', 'align'],
                    'nightshift@acme.test',
                ),
                'tags' => ['sprocket:9330', 'blueprint:mk4'],
            ],
            [
                'state' => 'pending',
                'queue' => 'webhooks',
                'job' => new PingSatellite(
                    'https://acme.test/hooks/inventory',
                    ['event' => 'sprocket.reserved', 'sprocket_id' => 9330],
                    1.5,
                    false,
                ),
                // Tags keyed by name are encoded as a JSON object rather than an
                // array, which is the shape older payloads still carry.
                'tags' => ['owner' => 'nightshift@acme.test', 'webhook' => 'inventory'],
            ],
            [
                'state' => 'completed',
                'queue' => 'assembly',
                'job' => new AssembleSprocket(
                    'blueprints/sprocket-mk2.json',
                    80,
                    true,
                    ['degrease', 'align', 'torque'],
                    'dayshift@acme.test',
                ),
                'tags' => ['sprocket:7781', 'blueprint:mk2'],
            ],
            [
                'state' => 'completed',
                'queue' => 'notifications',
                'job' => new FlashBeacon(
                    'a1b2c3d4e5f60718293a4b5c6d7e8f90',
                    'Nightly telemetry uploaded.',
                    1,
                    new DateTimeImmutable('2026-08-02 22:30:00'),
                ),
                'tags' => ['beacon:a1b2c3d4', 'channel:mail'],
            ],
            [
                'state' => 'silenced',
                'queue' => 'notifications',
                'job' => new FlashBeacon(
                    'b7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e2',
                    'Heartbeat.',
                    0,
                    new DateTimeImmutable('2026-08-03 06:15:00'),
                ),
                'tags' => ['beacon:b7c8d9e0', 'channel:log'],
            ],
        ];
    }

    /**
     * Build a queue payload for the given demo job.
     *
     * @param  object  $job
     * @param  array<mixed>|null  $tags
     * @return \Laravel\Horizon\JobPayload
     */
    protected function payloadFor($job, ?array $tags = null)
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

        $payload = $payload->prepare($job);

        if ($tags === null) {
            return $payload;
        }

        return new JobPayload(json_encode(
            array_replace($payload->decoded, ['tags' => $tags])
        ));
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
