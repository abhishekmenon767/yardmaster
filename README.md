# Yardmaster

A driver-agnostic queue dashboard and control plane for Laravel.

Horizon is excellent and requires Redis. Pulse works everywhere and is read-only.
Yardmaster is aimed at the gap between them: record every job on every
connection, and expose exactly the operations each driver can actually perform.

> **Status: phase 1 of 6 — the telemetry spine.** Recording works end to end on
> every driver. There is no dashboard yet; that is phase 3.

## What works today

- One row per **job attempt**, on any driver, with no driver conditionals in the
  recorder — it listens only to framework queue events and the `Job` contract.
- **Wait time measured from dispatch**, not from the worker picking the job up,
  by injecting a millisecond timestamp into the payload at dispatch.
- **Parent linkage** — a job dispatched from inside another job records its
  parent, so a fan-out reads as a tree rather than fifty unrelated rows.
- **Model tags** — jobs are automatically tagged with the Eloquent models they
  carry (`App\Models\Order:4471`).
- **Pre-aggregated rollups** into minute, hour and day buckets with log-scale
  latency histograms, so percentiles survive the raw rows being trimmed away.
- **Redaction on by default**, and the serialized command is never stored.
- **Retention** via `php artisan yard:trim`, with payloads dropped well before
  the rows that carry them.

## Install

```bash
composer require iocod/yardmaster
php artisan vendor:publish --tag=yardmaster-migrations
php artisan migrate
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=yardmaster-config
```

Schedule the trimmer:

```php
Schedule::command('yard:trim')->everyFifteenMinutes();
```

## Design notes

**Attempts, not jobs, are the unit of storage.** A job released three times and
then failed is four rows. Anything else makes an honest attempt timeline
impossible.

**Two tables on two clocks.** `yard_runs` is the hot, expensive half and is
trimmed to hours. `yard_buckets` is cheap and kept for months. The split is not
an optimisation to add later — retrofitting it is a rewrite.

**Histograms rather than raw rows for percentiles.** Bucket `i` covers
`[10^(i/10), 10^((i+1)/10))` milliseconds. Histograms merge by addition, so a p95
over any range is a column-wise sum of buckets. That is what keeps a dashboard
fast at ten million jobs a week.

**A monitoring failure must never become an application failure.** Every
listener body and every write runs inside a rescue boundary. Register a handler
if you want to see what it swallowed:

```php
app(Yardmaster::class)->handleExceptionsUsing(fn ($e) => Log::debug($e->getMessage()));
```

## Known driver quirks, recorded rather than hidden

- `SyncJob::getQueue()` returns the literal string `'sync'` regardless of the
  queue requested. Yardmaster records that as-is; a dashboard that invents a
  queue name is worse than one that shows an unfamiliar one.
- SQS reports its queue as a full URL. The recorder normalises it to the final
  path segment so one queue does not appear twice.

## Configuration worth knowing

| Env | Default | Purpose |
| --- | --- | --- |
| `YARDMASTER_ENABLED` | `true` | Master switch. Off means no listeners at all. |
| `YARDMASTER_DB_CONNECTION` | app default | Point telemetry at its own connection. |
| `YARDMASTER_SAMPLE` | `1.0` | Fraction of attempts recorded. |
| `YARDMASTER_CAPTURE_PAYLOADS` | `true` | Store the (redacted) payload. |
| `YARDMASTER_REDACT` | `true` | Mask sensitive payload keys. |

## Development

```bash
composer test      # pest
composer lint      # pint
composer analyse   # phpstan
```

The Redis parity test skips automatically without a running server; CI provides
one.

## Roadmap

| Phase | | Status |
| --- | --- | --- |
| 1 | Telemetry spine | **done** |
| 2 | Driver adapters and the capability gate | next |
| 3 | JSON API and dashboard | |
| 4 | Redis stream ingest, sampling, Octane, serverless | |
| 5 | Failure clustering, alerting, worker fleet | |
| 6 | Docs, CI matrix, PHPStan level 9, 1.0 | |

Static analysis currently passes at **level 8**. Level 9 needs typed config
accessors throughout and is tracked for phase 6.

## Licence

MIT.
