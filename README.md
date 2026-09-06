# Yardmaster

A driver-agnostic queue dashboard and control plane for Laravel.

Horizon is excellent and requires Redis. Pulse works everywhere and is read-only.
Yardmaster is aimed at the gap between them: record every job on every
connection, and expose exactly the operations each driver can actually perform.

> **Status: phase 4 of 6 — scale and hardening.** Recording, control, the API,
> the dashboard, Redis stream ingest, sampling, Octane and serverless modes all
> work end to end. Failure clustering, alerting and the worker fleet are next.

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

## The capability gate

A dashboard action is never "delete this job". It is "ask the adapter whether
deleting by id is possible on this connection, and render accordingly".

```php
use Iocod\Yardmaster\Drivers\AdapterManager;
use Iocod\Yardmaster\Drivers\Capability;

$adapter = app(AdapterManager::class)->for('sqs');

$adapter->supports(Capability::PeekPayloads);   // false
$adapter->depth('reports');                     // ~120 pending, marked approximate
$adapter->peek('reports');                      // throws UnsupportedCapability
```

An unsupported operation refuses loudly and names itself rather than returning
an empty result, because a control that quietly does nothing is how a dashboard
convinces an operator that a backed-up queue is idle.

| Capability | database | redis | sqs |
| --- | :---: | :---: | :---: |
| Count pending / delayed / reserved | exact | exact | approximate |
| Oldest job age | yes | yes | — |
| List queues | yes | yes | yes |
| Peek payloads | yes | yes | — |
| Delete one by id | yes | yes¹ | — |
| Promote a delayed job | yes | yes | — |
| Purge a whole queue | yes | yes | yes² |

¹ Redis has no per-job handle, so a job is addressed by its payload uuid and
located by a bounded scan (`yardmaster.drivers.redis.max_scan`).
² Rate-limited by SQS to once per 60 seconds; `purgeCooldownRemaining()` reports
the wait *before* the operator clicks.

**Any other driver — including beanstalkd — resolves to the null adapter:** full
recorded history from Plane A, every live control correctly disabled. That is
the architecture degrading as designed rather than the dashboard refusing to
load. A beanstalkd adapter will land once it can be tested against a real
server; shipping one that has never run would be exactly the kind of unverified
claim this package exists to avoid.

Applications can register their own:

```php
app(AdapterManager::class)->extend('kafka', fn ($connection, $config) => new KafkaAdapter(...));
```

## The dashboard

Visit `/yardmaster`. Assets ship compiled inside the package, so there is no npm
step, no CDN and nothing to add to a Content-Security-Policy.

Controls render from the capability report rather than from a driver name: on an
SQS connection, *Inspect* and *Delete* are disabled and say **"The sqs driver
cannot peek payloads"** on hover. The gate is not decoration — the API refuses
the same call with the same wording, so a stale page cannot get around it.

### Authorisation

Two gates, and they are separate on purpose. Neither is granted outside `local`
until you define it:

```php
Gate::define('viewYardmaster', fn ($user) => $user->isAdmin());
Gate::define('manageYardmaster', fn ($user) => $user->isOncall());
```

Everything that changes queue state — purge, delete, promote, retry, discard —
requires the second gate and is written to `yard_actions` with the actor, their
IP and what was affected.

### The JSON API

The dashboard is one client of a versioned API, not the only way in:

```
GET    /yardmaster/api/v1/meta                 capabilities per connection
GET    /yardmaster/api/v1/queues               live depth, exact or approximate
GET    /yardmaster/api/v1/queues/jobs          peek without consuming
GET    /yardmaster/api/v1/runs                 filterable attempt log
GET    /yardmaster/api/v1/runs/{uuid}          payload, attempt chain, children
GET    /yardmaster/api/v1/metrics              throughput, percentiles, series
GET    /yardmaster/api/v1/failures             failed attempts
GET    /yardmaster/api/v1/actions              audit trail
GET    /yardmaster/api/v1/stream               server-sent events

POST   /yardmaster/api/v1/queues/purge         } all require
DELETE /yardmaster/api/v1/queues/jobs          } manageYardmaster
POST   /yardmaster/api/v1/queues/jobs/promote  } and are audited
POST   /yardmaster/api/v1/failures/retry       }
DELETE /yardmaster/api/v1/failures             }
```

Live updates use server-sent events — one long-lived read connection, no Reverb
and no websocket server to run first. Append `?live=0` where a proxy buffers
`text/event-stream` into uselessness; the dashboard falls back to polling.

## Cost

Measured, not asserted. 3,000 no-op jobs through a real worker on PHP 8.3 and
SQLite, median of three runs, each starting from an empty table:

| Configuration | ms per job | Overhead |
| --- | ---: | ---: |
| Yardmaster disabled | 0.079 | — |
| `database` ingest, `flush_threshold=1` (default) | 1.095 | **1.02 ms** |
| `database` ingest, `flush_threshold=25` | 0.267 | **0.19 ms** |
| `redis` ingest, `flush_threshold=1` | 0.200 | **0.12 ms** |
| `redis` ingest, `flush_threshold=25` | 0.177 | **0.10 ms** |

Read that table before tuning anything. The default is the safe one: every
attempt is durable the instant it ends, so a worker killed outright loses
nothing. It is also the slowest, because the aggregate write is paid once per
job rather than once per batch.

Two knobs, in the order worth reaching for them:

1. **Raise `flush_threshold`** — an 8× improvement for a database ingest, at the
   cost of losing up to that many *attempts* if a worker is killed outright.
   What is at risk is telemetry, never work.
2. **Switch to `redis` ingest** — a flush becomes one `XADD` and aggregation
   moves to a separate process, which is why batching barely matters there.

SQLite's per-statement cost dominates the database rows above; MySQL and
Postgres will land elsewhere. Run the numbers on your own hardware before
quoting them.

## Scaling the ingest

```env
YARDMASTER_INGEST_DRIVER=redis
YARDMASTER_REDIS_CONNECTION=yardmaster   # not the one your queue uses
```

```bash
php artisan yard:work        # drain the stream into storage
php artisan yard:restart     # ask drainers to stand down, during deploys
```

Run `yard:work` under a process monitor next to your queue workers. The stream
is capped, so a drainer that falls behind or dies costs bounded memory and drops
the oldest telemetry rather than filling the Redis instance your application
depends on. Only one drainer runs at a time — two would read the same batch and
write it twice, quietly doubling every count on the dashboard.

**No long-lived processes** (Vapor, Cloud Run, Lambda)? Put it on the scheduler:

```php
Schedule::command('yard:work --once')->everyMinute();
Schedule::command('yard:trim')->everyFifteenMinutes();
```

**Octane** is handled: the buffer is drained on `RequestTerminated` and cleared
on `RequestReceived`, so a worker serving its second request never inherits the
first one's state.

## Sampling

```env
YARDMASTER_SAMPLE=0.1   # record one attempt in ten
```

Sampled figures are **scaled back up and marked approximate** rather than
silently under-reported, and buckets never mix rates: an estimate scaled from
10% and an exact count are different kinds of number, and adding them would
produce a third kind that is neither. Latency percentiles are unaffected — a
sample of a distribution has the same shape as the whole.

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

That is the whole installation for most applications: the default `database`
ingest needs no extra process at all.

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
composer test        # pest
composer lint        # pint
composer analyse     # phpstan
npm run build        # rebuild dist/ after changing resources/js

# A real application to click around in, seeded through a real worker:
vendor/bin/testbench serve
```

`dist/` is committed on purpose: an application installs with composer and gets
a working dashboard without ever running npm.

Redis-backed tests skip automatically without a running server; CI provides one.
No test ever reaches AWS — the SQS suite replaces the transport with a mock
handler, so the real client, request construction and response parsing are all
still exercised.

The shared contract in `tests/Contracts/AdapterContract.php` is written once and
run against every adapter. Adding a driver means adding a class and a capability
map, then running that file against it. If that is ever not true, the
abstraction has leaked.

## Roadmap

| Phase | | Status |
| --- | --- | --- |
| 1 | Telemetry spine | **done** |
| 2 | Driver adapters and the capability gate | **done** (database, redis, sqs) |
| 3 | JSON API and dashboard | **done** |
| 4 | Redis stream ingest, sampling, Octane, serverless | **done** |
| 5 | Failure clustering, alerting, worker fleet | next |
| 6 | Docs, CI matrix, PHPStan level 9, 1.0 | |

Static analysis currently passes at **level 8**. Level 9 needs typed config
accessors throughout and is tracked for phase 6.

## Licence

MIT.
