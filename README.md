<h1 align="center">HorizonXFlow</h1>

<p align="center">Live queue-flow visibility and operational insights for Laravel Horizon.</p>

<p align="center">
<a href="https://github.com/nsumbadze/horizonxflow/actions/workflows/tests.yml"><img src="https://github.com/nsumbadze/horizonxflow/actions/workflows/tests.yml/badge.svg?branch=main" alt="Build Status"></a>
<a href="LICENSE.md"><img src="https://img.shields.io/badge/license-MIT-green" alt="License"></a>
</p>

HorizonXFlow is an independently maintained project based on [Laravel Horizon](https://github.com/laravel/horizon). It retains Horizon's dashboard and code-driven worker configuration while adding a live operational workspace for understanding how jobs move through queues. It is not an official Laravel product.

## Live Flow

**Live Flow** is available at `/horizon/live-flow`. It visualises producers, queues, jobs, workers, and results in real time across Redis and database queue drivers.

<p align="center">
<img src="art/live-flow.png" alt="HorizonXFlow Live Flow workspace">
</p>

Under a row of queue KPIs, the workspace is organised into four areas:

- **Flow** — the queue topology as an SVG **graph** (producers → queues → workers → completed/failed, with per-edge throughput), or the same data as a filterable, sortable **queue table**. Selecting a node opens the **Inspector**, which shows that node's metrics, drain ETA, failure rate, recent job classes, and a suggested action when a queue is under backpressure.
- **Activity** — a rolling stream of jobs entering, completing, and failing.
- **Insights** — an incident timeline (long waits, job failures, supervisor deployments), monitored tags, and recent batches.
- **Horizon controls** — pause and continue the master supervisors or an individual supervisor.

The active workspace, graph/table mode, time window, queue filter, and selected node are reflected in the query string, so operational views can be shared directly.

To explore without a live queue, run `composer serve:demo`. This boots the workbench application with generated demo data.

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

### Abilities

- `viewHorizon` — required to enter the dashboard (existing Horizon gate).
- `controlHorizon` — required for mutation endpoints (`POST /jobs/retry/{id}`, `POST /masters/{action}`, `POST /supervisors/{name}/{action}`). When the gate is undefined, mutations are only allowed in `local` and `testing` environments; everywhere else, define the gate in `HorizonApplicationServiceProvider::gate()` to enable destructive actions for a trusted subset of users.

### Environment Variables

- `HORIZONXFLOW_FLOW_SOURCE` — overrides `flow.source`.
- `HORIZONXFLOW_FLOW_SOURCES` — comma-separated source list when `flow.source = auto`.
- `HORIZONXFLOW_FLOW_RECENT_JOBS_MAX` — overrides `flow.recent_jobs.max`.
- `HORIZONXFLOW_FLOW_QUEUE_KEYS_TTL` — overrides `flow.cache.queue_keys_ttl`.
- `HORIZONXFLOW_FLOW_PAYLOAD_TTL` — overrides `flow.cache.payload_ttl`.
- `HORIZONXFLOW_DISCOVER_DATABASE_QUEUES` — overrides `flow.database.discover_connections`.
- `QUEUE_FAILED_TABLE` — overrides `flow.database.failed_table`.

## Upstream Horizon

HorizonXFlow is based on Laravel Horizon and keeps its existing dashboard, queue supervision, metrics, and worker configuration. Refer to the [Laravel Horizon documentation](https://laravel.com/docs/horizon) for inherited Horizon behaviour.

Issues caused by HorizonXFlow changes should be reported in this repository. Upstream Laravel Horizon maintains its own issue tracker and release process.

## Contributing

Contributions are welcome. Please read the [contribution guide](.github/CONTRIBUTING.md) and [Code of Conduct](.github/CODE_OF_CONDUCT.md) before opening an issue or pull request.

## Security

Do not disclose security vulnerabilities in public issues. Follow this repository's [security policy](.github/SECURITY.md) to report them privately.

## License

HorizonXFlow is released under the [MIT license](LICENSE.md). The original Laravel Horizon copyright and license notice are retained.
