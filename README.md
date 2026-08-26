<h1 align="center">HorizonFlow</h1>

<p align="center">Live queue-flow visibility and operational insights for Laravel Horizon.</p>

<p align="center">
<a href="https://github.com/nsumbadze/horizonflow/actions/workflows/tests.yml"><img src="https://github.com/nsumbadze/horizonflow/actions/workflows/tests.yml/badge.svg?branch=main" alt="Build Status"></a>
<a href="LICENSE.md"><img src="https://img.shields.io/badge/license-MIT-green" alt="License"></a>
</p>

HorizonFlow is an independently maintained fork of [Laravel Horizon](https://github.com/laravel/horizon). It retains Horizon's dashboard and code-driven worker configuration while adding a live operational workspace for understanding how jobs move through queues. HorizonFlow is not an official Laravel product.

## Installation

HorizonFlow is installed instead of `laravel/horizon`; the two packages must not be installed together.

For a new installation, require HorizonFlow and publish Horizon's application service provider and configuration:

```bash
composer require nsumbadze/horizonflow
php artisan horizon:install
```

Laravel package discovery registers `Laravel\Horizon\HorizonServiceProvider`. The install command publishes `config/horizon.php` and creates `app/Providers/HorizonServiceProvider.php`, where dashboard authorization is configured. HorizonFlow's additional settings have working defaults; publish them only when you need to customize Live Flow:

```bash
php artisan vendor:publish --tag=horizonxflow-config
```

Run Horizon as you would the upstream package:

```bash
php artisan horizon
```

### Replacing Laravel Horizon

Applications already using `laravel/horizon` should preserve their `config/horizon.php` and `app/Providers/HorizonServiceProvider.php`, remove the upstream package requirement, and then install HorizonFlow with dependency updates allowed:

```bash
composer remove laravel/horizon --no-update
composer require nsumbadze/horizonflow --with-all-dependencies
```

The fork intentionally retains the `Laravel\Horizon` PHP namespace, service providers, Artisan commands, configuration shape, dashboard routes, and Redis data conventions. Composer declares that HorizonFlow replaces the Laravel Horizon `5.x` line, preventing both implementations from being installed together. Review [UPGRADE.md](UPGRADE.md) and test the change in a non-production environment before deployment; HorizonFlow has its own releases and version numbers and does not claim the same versions as upstream Horizon.

### Compatibility

HorizonFlow requires PHP 8.0 or later, Laravel 9.21 through 13, the JSON, PCNTL, and POSIX PHP extensions, and a Redis connection supported by Laravel. Install either the PhpRedis extension or `predis/predis`. PCNTL and POSIX are not available on Windows, so HorizonFlow should run in a Linux environment or a compatible container/virtual machine.

## Live Flow

**Live Flow** is available at `/horizon/live-flow`. It visualises producers, queues, jobs, workers, and results in real time across Redis and database queue drivers.

<p align="center">
<img src="art/live-flow.png" alt="HorizonFlow Live Flow workspace">
</p>

Under a row of queue KPIs, the workspace is organised into four areas:

- **Flow** — the queue topology as an SVG **graph** (producers → queues → workers → completed/failed, with per-edge throughput), or the same data as a filterable, sortable **queue table**. Selecting a node opens the **Inspector**, which shows that node's metrics, drain ETA, failure rate, recent job classes, and a suggested action when a queue is under backpressure.
- **Activity** — a rolling stream of jobs entering, completing, and failing.
- **Insights** — an incident timeline (long waits, job failures, supervisor deployments), monitored tags, and recent batches.
- **Horizon controls** — pause and continue the master supervisors or an individual supervisor.

Selecting a Redis queue in the Inspector also exposes queue pause/resume controls and safe cancellation actions for its pending or running jobs. The toolbar and the Inspector can also dispatch a job onto a queue; see [Dispatching Jobs](#dispatching-jobs).

The active workspace, graph/table mode, time window, queue filter, and selected node are reflected in the query string, so operational views can be shared directly.

To explore without a live queue, run `composer serve:demo`. This boots the workbench application with generated demo data and seeds a handful of failed jobs you can open and retry.

### Configuration

Live-flow behaviour is configured via `config/horizonxflow.php`:

| Key                                     | Default      | Description |
| --------------------------------------- | ------------ | ----------- |
| `flow.source`                           | `redis`      | One of `redis`, `database`, `auto`, `mock`. `auto` merges every configured source into a single payload. |
| `flow.sources`                          | `[redis]`    | Source list when `flow.source = auto`. Set via `HORIZONXFLOW_FLOW_SOURCES` (comma-separated). |
| `flow.recent_jobs.max`                  | `50`         | Cap on per-queue job rows returned to the inspector. |
| `flow.cache.queue_keys_ttl`             | `10`         | Seconds the Redis `SCAN` for queue keys is cached for. Set to `0` to disable. |
| `flow.cache.payload_ttl`                | `1`          | Seconds the full repository payload is memoised across requests. |
| `flow.database.connections`             | `[]`         | Explicit list of connections for the database driver. Empty means auto-discover from `queue.connections`. |
| `flow.database.discover_connections`    | `false`      | When `true` the database driver also walks `database.connections` (driver: mysql/pgsql/sqlite/sqlsrv) to find candidate `jobs` tables. |
| `flow.database.failed_table`            | `failed_jobs`| Table that holds failed-jobs entries. |
| `dispatch.enabled`                      | `true`       | Whether jobs may be dispatched from the dashboard at all. |
| `dispatch.discover`                     | `true`       | Whether `dispatch.paths` are walked for job classes. |
| `dispatch.paths`                        | `[]`         | Directories searched for job classes. Empty means `app/Jobs`. |
| `dispatch.allowed`                      | `[]`         | Class names or `Str::is` patterns an operator may dispatch. Empty means every discovered job. |
| `dispatch.denied`                       | `[]`         | Class names or patterns that may never be dispatched. Takes precedence over `dispatch.allowed`. |
| `dispatch.max_delay`                    | `86400`      | Largest delay, in seconds, an operator may ask for. |
| `cancellation.run_ttl`                  | `3600`       | Seconds a cancelled run keeps blocking its jobs before lifting on its own. |
| `cancellation.purge_limit`              | `5000`       | Most pending jobs walked when purging a cancelled run. |

### Routes

| Path | Returns |
| ---- | ------- |
| `GET /horizon/api/flow`              | Full live-flow payload (kept for back-compat). |
| `GET /horizon/api/flow/summary`      | Header KPIs plus `failed_in_window`, `window_seconds`, and a `health[]` block per source. |
| `GET /horizon/api/flow/graph`        | Nodes and edges for the SVG flow graph. |
| `GET /horizon/api/flow/queues`       | Filterable / sortable queue rows (rows omit per-row job arrays for cheapness). |
| `GET /horizon/api/flow/queue-jobs`   | Recent jobs + job-classes for a single queue (`?key=driver:connection:name`). |
| `GET /horizon/api/flow/events`       | Activity stream. Pass `?since=<unix ts>` for incremental polling. |
| `GET /horizon/api/flow/incidents`    | Recent incidents (long waits, job failures, supervisor deployments) for the Insights timeline. |
| `POST /horizon/api/flow/queues/pause`  | Pause one Redis queue while retaining pending and newly dispatched jobs. |
| `POST /horizon/api/flow/queues/resume` | Resume processing one paused Redis queue. |
| `POST /horizon/api/jobs/{id}/cancel`   | Cancel a pending job or request cooperative cancellation of a running job. |
| `GET /horizon/api/jobs/dispatchable`   | The job classes an operator may dispatch, plus the configured queue connections. |
| `GET /horizon/api/jobs/dispatchable/parameters` | The constructor parameters of one dispatchable job class (`?class=App\Jobs\Example`). |
| `POST /horizon/api/jobs/dispatch`      | Dispatch a job with the given constructor arguments and queue options. |
| `GET /horizon/api/flow/runs`           | The runs that are currently cancelled, with their counters and expiry. |
| `POST /horizon/api/flow/runs/cancel`   | Cancel a whole run, by `group` or by a `job` that belongs to it. |
| `POST /horizon/api/flow/runs/release`  | Lift a run cancellation. |
| `GET /horizon/api/jobs/{id}/run`       | The run a job belongs to, and whether that run is cancelled. |

### Abilities

- `viewHorizon` — required to enter the dashboard (existing Horizon gate).
- `controlHorizon` — required for mutation endpoints (`POST /jobs/retry/{id}`, `POST /jobs/{id}/cancel`, `POST /jobs/dispatch`, `POST /flow/queues/{action}`, `POST /flow/runs/{action}`, `POST /masters/{action}`, `POST /supervisors/{name}/{action}`) and for the reads that back them: `GET /jobs/failed/{id}/parameters`, `GET /jobs/dispatchable`, `GET /jobs/dispatchable/parameters`, `GET /jobs/{id}/run`, and `GET /flow/runs`. When the gate is undefined, mutations are only allowed in `local` and `testing` environments; everywhere else, define the gate in `HorizonApplicationServiceProvider::gate()` to enable destructive actions for a trusted subset of users.

Dispatching a job is the most powerful control on the dashboard: it constructs and queues an application job with operator-supplied arguments. Treat `controlHorizon` as the boundary that protects it, and define the gate explicitly rather than relying on the `local` / `testing` fallback — an application deployed with `APP_ENV=local` would otherwise expose dispatch to anyone who can reach the dashboard. Set `dispatch.enabled` to `false` to remove the capability entirely.

### Environment Variables

- `HORIZONXFLOW_FLOW_SOURCE` — overrides `flow.source`.
- `HORIZONXFLOW_FLOW_SOURCES` — comma-separated source list when `flow.source = auto`.
- `HORIZONXFLOW_FLOW_RECENT_JOBS_MAX` — overrides `flow.recent_jobs.max`.
- `HORIZONXFLOW_FLOW_QUEUE_KEYS_TTL` — overrides `flow.cache.queue_keys_ttl`.
- `HORIZONXFLOW_FLOW_PAYLOAD_TTL` — overrides `flow.cache.payload_ttl`.
- `HORIZONXFLOW_DISCOVER_DATABASE_QUEUES` — overrides `flow.database.discover_connections`.
- `QUEUE_FAILED_TABLE` — overrides `flow.database.failed_table`.
- `HORIZONXFLOW_DISPATCH_ENABLED` — overrides `dispatch.enabled`.
- `HORIZONXFLOW_DISPATCH_DISCOVER` — overrides `dispatch.discover`.
- `HORIZONXFLOW_DISPATCH_MAX_DELAY` — overrides `dispatch.max_delay`.
- `HORIZONXFLOW_CANCELLED_RUN_TTL` — overrides `cancellation.run_ttl`.
- `HORIZONXFLOW_CANCELLED_RUN_PURGE_LIMIT` — overrides `cancellation.purge_limit`.

## Job and Queue Controls

The Live Flow Inspector can pause an individual Redis queue and cancel one pending or running job. These controls deliberately preserve Laravel's queue safety boundaries:

- Pausing a queue does not reject dispatches or kill workers. A job already running finishes, while pending and newly dispatched jobs remain queued until the queue is resumed.
- Cancelling a pending job atomically removes its exact payload from the ready list or delayed set. If a worker reserves it first, HorizonFlow records a cooperative cancellation request instead of reporting a false success.
- A running worker process is never force-killed. Side effects already performed by a job cannot be rolled back by HorizonFlow.
- Cancelled jobs remain visible in the Inspector with their cancellation time and operator identifier. Repeated requests are idempotent, and completed or failed jobs return `409 Conflict`.

Mutation routes use the `controlHorizon` ability described above. Queue connection and queue names are validated server-side, raw job payloads are never accepted from or returned to the control UI, and every destructive action has an explicit confirmation step.

### Cooperative cancellation checkpoints

A running job must opt in before it can stop between units of work. Add `InteractsWithCancellation` and return from `handle()` when a checkpoint acknowledges the request:

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\InteractsWithQueue;
use Laravel\Horizon\Concerns\InteractsWithCancellation;

class SendCampaignMail implements ShouldQueue
{
    use InteractsWithQueue;
    use InteractsWithCancellation;

    public function handle(): void
    {
        foreach ($this->recipients as $recipient) {
            if ($this->cancelIfRequested()) {
                return;
            }

            $this->sendTo($recipient);
        }
    }
}
```

Place checkpoints before idempotent units of work. A cancellation requested while a single non-interruptible call is executing—for example, an SMTP hand-off—takes effect only after that call returns and the next checkpoint is reached.

### Cancelling a whole run

Cancelling one job stops one job. That is rarely what you want when a job chains its own successor or fans work out: kill page 6 and the walk carries on from page 7. HorizonFlow can stop the whole run instead.

A run is whatever a job says it is. Jobs that belong together return the same key:

```php
use Laravel\Horizon\Concerns\InteractsWithCancellation;

final class FetchCitrusPageJob implements ShouldQueue
{
    use InteractsWithCancellation;

    public function __construct(
        public readonly int $companyId,
        public readonly int $page = 1,
    ) {
    }

    public function cancellationGroup(): ?string
    {
        return "citrus-sync:{$this->companyId}";
    }

    public function handle(): void
    {
        foreach ($this->pageOfProducts() as $product) {
            if ($this->cancelIfRequested()) {
                return;
            }

            $this->process($product);
        }

        self::dispatch($this->companyId, $this->page + 1);
    }
}
```

Give the jobs it fans out to the same key and one cancellation covers all of them.

Open any job in Live Flow and it now shows the run it belongs to, with **cancel run** next to the usual per-job actions. Cancelling has three effects, and they do not all land at once:

- **Pending jobs are purged now.** Matching payloads are removed from the ready list and delayed set and retained as cancelled, exactly as a single cancellation would.
- **Queued jobs are dropped at pickup.** A worker refuses a job whose run is cancelled before `handle()` runs. This is what ends a self-chained walk: the job in flight may still queue its successor, but that successor never starts.
- **A running job stops at its next checkpoint.** Only if it calls `cancelIfRequested()`. Without checkpoints, the job in flight finishes its work; the run still stops at the next link.

A cancellation is a standing block, not a one-off sweep, so a run cannot re-seed itself while it stands. It lifts on its own after `cancellation.run_ttl`, and the Inspector lists every active cancellation with how many jobs were purged, how many have been dropped since, and how long is left — with **lift** to end it early.

Groups may contain letters, numbers, dashes, underscores, dots, and colons, and are rejected otherwise so a group can never address unrelated Redis keys. Purging walks at most `cancellation.purge_limit` pending jobs; when it hits that limit the response says so rather than reporting a clean sweep.

Reading a job's run means unserializing its command, so workers do no payload work unless some run is actually cancelled — and even then, only payloads whose job class declares `cancellationGroup()` are unserialized at all.

The pickup check is deliberately fail-open: if the cancellation lookup itself errors, the job runs as it would have without the feature. A Redis blip silently discarding jobs would be the worse failure. Run cancellation is therefore best-effort, not a guarantee.

Queue and job controls currently support Redis queues. Database queues remain observable in Live Flow but do not expose these mutation controls.

When `flow.source` is `mock`, the Inspector exposes the same controls as a session-only visual simulation. Pausing, cancelling, and retrying update only the browser state and never call a mutation endpoint or change Redis. Mock failures are available from Live Flow's queue Inspector and Activity workspace; Horizon's separate Failed Jobs page continues to show only real failed jobs.

## Retry With Parameters

A failed job usually fails because of what it was handed: a wrong path, a batch size that was too large, a flag left on. Horizon can only push that same job back onto the queue unchanged, so the normal fix is a tinker session or a one-off command. HorizonFlow lets you change the arguments and retry from the dashboard instead.

Open a failed job and press **Edit Parameters**. HorizonFlow reads the job class constructor and lists every parameter it accepts, prefilled with the values the failed job was queued with:

<p align="center">
<img src="art/retry-parameters.png" alt="Editing a failed job's parameters before retrying it">
</p>

Change what you need and press **Retry With Parameters**. The job is queued as a normal retry, so it still shows up under the original job's retry history.

What you can edit:

- `string`, `int`, `float`, `bool`, `array` and `iterable` parameters, plus untyped ones holding those values. Arrays are edited as JSON.
- Nullable parameters get a **Send as null** toggle.
- Parameters that were never passed still appear, prefilled with their declared default.

What you cannot edit, and why the panel says so next to each one:

- Objects and Eloquent models. They are shown read-only rather than hidden, so you can still see what the job was carrying.
- Queued closures, and jobs whose class no longer exists in the application.

Values are cast to the parameter's declared type before the job is queued (`"9"` becomes `9` for an `int`). Anything that does not fit is rejected with a `422` and the reason, and nothing is queued. Jobs implementing `ShouldBeEncrypted` are decrypted for inspection and re-encrypted on the way out.

Editing parameters is gated by `controlHorizon`, the same ability an ordinary retry needs. Both the read and the retry go through it:

| Path | Returns |
| ---- | ------- |
| `GET /horizon/api/jobs/failed/{id}/parameters` | The job's constructor parameters, their current values, and whether each one may be edited. |
| `POST /horizon/api/jobs/retry/{id}`            | Retries the job. Accepts an optional `parameters` object of overrides. |

To try it locally, `composer serve:demo` seeds three failed demo jobs. You can also seed or remove them directly:

```bash
php artisan horizonxflow:demo-jobs
php artisan horizonxflow:demo-jobs --clear
```

## Dispatching Jobs

Putting one job on a queue by hand normally means a tinker session or a one-off Artisan command. HorizonFlow can do it from Live Flow instead, with the same reflection that backs [Retry With Parameters](#retry-with-parameters).

Press **Dispatch job** in the Live Flow toolbar, or **dispatch to queue** in the Inspector to arrive with a queue already chosen. Pick a class on the left, fill in its constructor arguments, and set where and when it runs:

- **connection** — any connection in `config/queue.php`. Leaving it alone uses the job's own `$connection`, or the application default.
- **queue** — free text, with the queues Live Flow has already seen offered as suggestions. Leaving it alone uses the job's own `$queue`, or the connection default.
- **delay** — in seconds, minutes, or hours, capped by `dispatch.max_delay`.

The footer states the connection, queue, and delay the job will actually land with before you press **Dispatch job**.

### Which jobs appear

Discovery walks `app/Jobs` for classes implementing `ShouldQueue` that can be constructed. Point it somewhere else with `dispatch.paths`, and turn it off entirely with `dispatch.discover`.

The listed set is the whole dispatchable surface. A class that is neither discovered nor named in `dispatch.allowed` is rejected with a `422`, so a request can never reach an arbitrary queueable class inside the framework or a third-party package:

```php
'dispatch' => [
    'allowed' => [
        'App\Jobs\*',
        'Vendor\Package\Jobs\SyncCatalog',
    ],

    'denied' => [
        'App\Jobs\Billing\*',
    ],
],
```

Both lists accept exact class names or `Str::is` patterns, and `dispatch.denied` always wins. An exact entry in `dispatch.allowed` is dispatchable even when discovery never saw it, which is how you expose a job that lives outside `app/Jobs`. Set `dispatch.enabled` to `false` to remove the controls and refuse the endpoints outright.

### What you can pass

Constructor parameters follow the same rules as retrying with edited parameters: `string`, `int`, `float`, `bool`, `array` and `iterable`, plus untyped ones. Arrays are entered as JSON, nullable parameters get a **send as null** toggle, and optional parameters left blank fall back to their declared defaults.

A job that *requires* something HorizonFlow cannot build — an Eloquent model, a date object, any other class — is shown with the parameter that blocks it and cannot be dispatched. Values are cast to the declared type before the job is constructed, and anything that does not fit is rejected with a `422` and the reason, without queueing.

Dispatching is gated by `controlHorizon`, the same ability pausing a queue needs. When Live Flow is showing demo data (`flow.source = mock`), dispatching is simulated in the browser and no job reaches Redis.

To try it locally, `composer serve:demo` points discovery at HorizonFlow's own demo jobs. `AssembleSprocket` and `PingSatellite` cover every editable parameter type, and `FlashBeacon` shows what a job that requires a `DateTimeImmutable` looks like when it cannot be dispatched.

## Upstream Horizon

HorizonFlow is derived from Laravel Horizon and keeps its existing dashboard, queue supervision, metrics, and worker configuration. Refer to the [Laravel Horizon documentation](https://laravel.com/docs/horizon) for inherited Horizon behaviour.

Laravel Horizon was created by Taylor Otwell and is maintained by Laravel and its contributors. HorizonFlow retains Laravel Horizon's original copyright and license notices. Issues caused by HorizonFlow changes should be reported in this repository; bugs that also exist in unmodified Laravel Horizon may belong in the upstream issue tracker. Upstream Laravel Horizon has its own release process.

## Contributing

Contributions are welcome. Please read the [contribution guide](.github/CONTRIBUTING.md) and [Code of Conduct](.github/CODE_OF_CONDUCT.md) before opening an issue or pull request.

## Security

Do not disclose security vulnerabilities in public issues. Follow this repository's [security policy](.github/SECURITY.md) to report them privately.

## License

HorizonFlow is released under the [MIT license](LICENSE.md). The original Laravel Horizon copyright and license notice are retained.
