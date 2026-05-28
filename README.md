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

## Horizonxbrain Extensions

This fork extends Horizon with a **Live Flow** dashboard view (`/horizon/flow`) that visualises producers, queues, jobs, workers, and results in real time across Redis and database queue drivers. The view polls focused endpoints (summary, graph, queues, queue-jobs, events) and merges them into a single live payload, with per-edge particle animation driven by actual throughput.

### Configuration

Live-flow behaviour is configured via `config/horizonxbrain.php`:

| Key                                     | Default      | Description |
| --------------------------------------- | ------------ | ----------- |
| `flow.source`                           | `redis`      | One of `redis`, `database`, `auto`, `mock`. `auto` merges every configured source into a single payload. |
| `flow.sources`                          | `[redis]`    | Source list when `flow.source = auto`. Set via `HORIZONXBRAIN_FLOW_SOURCES` (comma-separated). |
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

### Abilities

- `viewHorizon` — required to enter the dashboard (existing Horizon gate).
- `controlHorizon` — required for mutation endpoints (`POST /jobs/retry/{id}`, `POST /masters/{action}`, `POST /supervisors/{name}/{action}`). When the gate is undefined the upstream behaviour is preserved (any viewer can mutate). Define it in `HorizonApplicationServiceProvider::gate()` to lock destructive actions to a subset of users.

### Environment Variables

- `HORIZONXBRAIN_FLOW_SOURCE` — overrides `flow.source`.
- `HORIZONXBRAIN_FLOW_SOURCES` — comma-separated source list when `flow.source = auto`.
- `HORIZONXBRAIN_FLOW_RECENT_JOBS_MAX` — overrides `flow.recent_jobs.max`.
- `HORIZONXBRAIN_FLOW_QUEUE_KEYS_TTL` — overrides `flow.cache.queue_keys_ttl`.
- `HORIZONXBRAIN_FLOW_PAYLOAD_TTL` — overrides `flow.cache.payload_ttl`.
- `HORIZONXBRAIN_DISCOVER_DATABASE_QUEUES` — overrides `flow.database.discover_connections`.
- `QUEUE_FAILED_TABLE` — overrides `flow.database.failed_table`.

## License

Laravel Horizon is open-sourced software licensed under the [MIT license](LICENSE.md).
