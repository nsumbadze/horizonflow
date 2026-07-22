<p align="center"><img width="373" height="60" src="/art/logo.svg" alt="Laravel Horizon"></p>

<p align="center">
<a href="https://github.com/laravel/horizon/actions"><img src="https://github.com/laravel/horizon/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/horizon"><img src="https://img.shields.io/packagist/dt/laravel/horizon" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/horizon"><img src="https://img.shields.io/packagist/v/laravel/horizon" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/horizon"><img src="https://img.shields.io/packagist/l/laravel/horizon" alt="License"></a>
</p>

## Introduction

Horizon provides a beautiful dashboard and code-driven configuration for your Laravel powered Redis queues. Horizon allows you to easily monitor key metrics of your queue system such as job throughput, runtime, and job failures.

All of your worker configuration is stored in a single, simple configuration file, allowing your configuration to stay in source control where your entire team can collaborate.

<p align="center">
<img src="https://laravel.com/img/docs/horizon-example.png">
</p>

## Official Documentation

Documentation for Horizon can be found on the [Laravel website](https://laravel.com/docs/horizon).

## Contributing

Thank you for considering contributing to Horizon! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

Please review [our security policy](https://github.com/laravel/horizon/security/policy) on how to report security vulnerabilities.

## Live Flow

This is a fork of [Laravel Horizon](https://github.com/laravel/horizon). Everything above is upstream Horizon; this section covers the one screen the fork adds on top of it.

**Live Flow** is a workspace at `/horizon/live-flow` that visualises producers, queues, jobs, workers, and results in real time across Redis and database queue drivers. It polls focused endpoints (summary, graph, queues, queue-jobs, events, incidents) and merges them into a single live payload.

<p align="center">
<img src="art/live-flow.png" alt="Live Flow">
</p>

Under a row of KPIs (pending, workers, delayed, failed, throughput, average wait) the screen is split into four tabs:

- **Flow** — the queue topology as an SVG **graph** (producers → queues → workers → completed/failed, with per-edge throughput), or the same data as a filterable, sortable **queue table**. Selecting a node opens the **Inspector**, which shows that node's metrics, drain ETA, failure rate, recent job classes, and a suggested action when a queue is under backpressure.
- **Activity** — a rolling stream of jobs entering, completing, and failing.
- **Insights** — an incident timeline (long waits, job failures, supervisor deployments), monitored tags, and recent batches.
- **Horizon controls** — pause and continue the master supervisors or an individual supervisor.

The active tab, graph/table mode, time window, queue filter, and selected node are all reflected in the query string, so any view is linkable — for example `/horizon/live-flow?view=flow&node=queue-notifications`.

To explore the screen without a live queue, run `composer serve:demo`, which boots the workbench app with `HORIZONXFLOW_FLOW_SOURCE=mock` and generated demo data.

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

## License

Laravel Horizon is open-sourced software licensed under the [MIT license](LICENSE.md).
