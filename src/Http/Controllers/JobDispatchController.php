<?php

namespace Laravel\Horizon\Http\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Laravel\Horizon\Exceptions\InvalidJobParameterException;
use Laravel\Horizon\Http\Middleware\AuthenticateElevatedControl;
use Laravel\Horizon\JobDispatchRegistry;
use Laravel\Horizon\JobParameterInspector;

class JobDispatchController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(AuthenticateElevatedControl::class);
    }

    /**
     * List the job classes an operator may dispatch and the options they may pick.
     *
     * @return array<string, mixed>
     */
    public function index(JobDispatchRegistry $registry, Config $config): array
    {
        return [
            'enabled' => $registry->enabled(),
            'max_delay' => $this->maxDelay($config),
            'default_connection' => (string) $config->get('queue.default', ''),
            'connections' => $this->connections($config),
            'jobs' => $registry->dispatchable(),
        ];
    }

    /**
     * Describe the constructor parameters of one dispatchable job class.
     *
     * @return array<string, mixed>
     */
    public function parameters(Request $request, JobDispatchRegistry $registry, JobParameterInspector $inspector): array
    {
        return $inspector->inspectClass($this->jobClass($request, $registry));
    }

    /**
     * Dispatch a job with the given constructor arguments and queue options.
     *
     * @return array<string, mixed>
     */
    public function store(
        Request $request,
        JobDispatchRegistry $registry,
        JobParameterInspector $inspector,
        Config $config
    ): array {
        $class = $this->jobClass($request, $registry);
        $options = $this->dispatchOptions($request, $config);

        try {
            $job = $inspector->buildJob($class, $this->arguments($request));
        } catch (InvalidJobParameterException $exception) {
            abort(422, $exception->getMessage());
        }

        return array_merge(['dispatched' => true, 'class' => $class], $this->dispatchJob($job, $options));
    }

    /**
     * Get the requested job class, ensuring it may be dispatched.
     */
    protected function jobClass(Request $request, JobDispatchRegistry $registry): string
    {
        $class = ltrim(trim((string) $request->input('class', '')), '\\');

        try {
            return $registry->ensureDispatchable($class);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }
    }

    /**
     * Get the constructor arguments from the request.
     *
     * @return array<string, mixed>
     */
    protected function arguments(Request $request): array
    {
        $parameters = $request->input('parameters', []);

        if (! is_array($parameters)) {
            abort(422, 'The job parameters must be given as an object.');
        }

        return $parameters;
    }

    /**
     * Get the validated queue options the job should be dispatched with.
     *
     * @return array{connection: string|null, queue: string|null, delay: int}
     */
    protected function dispatchOptions(Request $request, Config $config): array
    {
        return [
            'connection' => $this->connection($request, $config),
            'queue' => $this->queue($request),
            'delay' => $this->delay($request, $config),
        ];
    }

    /**
     * Get the requested queue connection, which must be configured.
     */
    protected function connection(Request $request, Config $config): ?string
    {
        $connection = trim((string) $request->input('connection', ''));

        if ($connection === '') {
            return null;
        }

        if (! is_array($config->get("queue.connections.{$connection}"))) {
            abort(422, "The [{$connection}] queue connection is not configured.");
        }

        return $connection;
    }

    /**
     * Get the requested queue name.
     */
    protected function queue(Request $request): ?string
    {
        $queue = trim((string) $request->input('queue', ''));

        if ($queue === '') {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9_.:-]{1,190}$/', $queue)) {
            abort(422, 'The queue name may only contain letters, numbers, dashes, underscores, dots, and colons.');
        }

        return $queue;
    }

    /**
     * Get the requested dispatch delay in seconds.
     */
    protected function delay(Request $request, Config $config): int
    {
        $delay = $request->input('delay', 0);

        if (is_null($delay) || $delay === '') {
            return 0;
        }

        if (! is_numeric($delay) || (string) (int) $delay !== (string) $delay) {
            abort(422, 'The delay must be given as a whole number of seconds.');
        }

        $delay = (int) $delay;
        $max = $this->maxDelay($config);

        if ($delay < 0 || $delay > $max) {
            abort(422, "The delay must be between 0 and {$max} seconds.");
        }

        return $delay;
    }

    /**
     * Get the largest delay an operator may request.
     */
    protected function maxDelay(Config $config): int
    {
        return max(0, (int) $config->get('horizonxflow.dispatch.max_delay', 86400));
    }

    /**
     * Get the queue connections an operator may dispatch onto.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function connections(Config $config): array
    {
        $connections = $config->get('queue.connections', []);

        if (! is_array($connections)) {
            return [];
        }

        $described = [];

        foreach ($connections as $name => $connection) {
            if (! is_string($name) || ! is_array($connection)) {
                continue;
            }

            $queue = $connection['queue'] ?? null;

            $described[] = [
                'name' => $name,
                'driver' => is_string($connection['driver'] ?? null) ? $connection['driver'] : null,
                'queue' => is_string($queue) && $queue !== '' ? $queue : null,
            ];
        }

        return $described;
    }

    /**
     * Dispatch the built job with the requested options.
     *
     * A job may pick its own connection, queue, or delay in its constructor,
     * so the options it actually carries are read back off the instance
     * rather than echoing what was asked for.
     *
     * @param  object  $job
     * @param  array{connection: string|null, queue: string|null, delay: int}  $options
     * @return array{connection: string|null, queue: string|null, delay: int}
     */
    protected function dispatchJob($job, array $options): array
    {
        // Every option is checked before the pending dispatch is created. A
        // PendingDispatch queues its job when it is destructed, so aborting
        // half way through configuring one would still dispatch the job.
        $this->ensureJobAcceptsOptions($job, $options);

        $pending = dispatch($job);

        if (! is_null($options['connection'])) {
            $pending->onConnection($options['connection']);
        }

        if (! is_null($options['queue'])) {
            $pending->onQueue($options['queue']);
        }

        if ($options['delay'] > 0) {
            $pending->delay($options['delay']);
        }

        return $this->effectiveOptions($job, $options);
    }

    /**
     * Read the connection, queue, and delay the job is carrying.
     *
     * @param  object  $job
     * @param  array{connection: string|null, queue: string|null, delay: int}  $options
     * @return array{connection: string|null, queue: string|null, delay: int}
     */
    protected function effectiveOptions($job, array $options): array
    {
        $delay = $this->jobProperty($job, 'delay');

        return [
            'connection' => $this->stringProperty($job, 'connection'),
            'queue' => $this->stringProperty($job, 'queue'),
            'delay' => is_numeric($delay) ? (int) $delay : $options['delay'],
        ];
    }

    /**
     * Read a queue option off the job as a string.
     *
     * @param  object  $job
     */
    protected function stringProperty($job, string $property): ?string
    {
        $value = $this->jobProperty($job, $property);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Read a property off the job without tripping over one it never declares.
     *
     * @param  object  $job
     * @return mixed
     */
    protected function jobProperty($job, string $property)
    {
        return property_exists($job, $property) ? ($job->{$property} ?? null) : null;
    }

    /**
     * Ensure the job exposes the queue options the operator asked for.
     *
     * @param  object  $job
     * @param  array{connection: string|null, queue: string|null, delay: int}  $options
     */
    protected function ensureJobAcceptsOptions($job, array $options): void
    {
        $required = array_filter([
            'connection' => is_null($options['connection']) ? null : 'onConnection',
            'queue' => is_null($options['queue']) ? null : 'onQueue',
            'delay' => $options['delay'] > 0 ? 'delay' : null,
        ]);

        foreach ($required as $option => $method) {
            if (! method_exists($job, $method)) {
                abort(422, sprintf(
                    'The [%s] job does not accept a %s option. Add the Illuminate\Bus\Queueable trait to the job.',
                    get_class($job),
                    $option
                ));
            }
        }
    }
}
