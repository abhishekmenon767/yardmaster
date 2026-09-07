# Yardmaster

A driver-agnostic queue dashboard and control plane for Laravel.

Horizon is excellent and requires Redis. Pulse works everywhere and is read-only.
Yardmaster is aimed at the gap between them: record every job on every
connection, and expose exactly the operations each driver can actually perform.

[![tests](https://img.shields.io/badge/tests-168%20passing-brightgreen)](.github/workflows/tests.yml)
[![phpstan](https://img.shields.io/badge/phpstan-level%209-brightgreen)](phpstan.neon.dist)
[![license](https://img.shields.io/badge/license-MIT-blue)](LICENSE.md)

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
use Abhishek\Yardmaster\Drivers\AdapterManager;
use Abhishek\Yardmaster\Drivers\Capability;

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

## Working alongside Horizon

Yardmaster does not ask you to replace Horizon. Where Horizon is installed, the
dashboard links straight to it — it owns the live view of the Redis queues it
already supervises, and duplicating that would be worse, not better. Yardmaster
covers what Horizon cannot see: your other connections, durable history,
grouped failures, and an audit trail.

Running both is the expected configuration, not a compromise.

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

## Issues, not rows

A bad deploy produces thousands of near-identical failed jobs. Rendering that as
thousands of rows is accurate and useless. Yardmaster groups failures by
exception class, message with the varying parts normalised out, and the first
application stack frame:

```
6x  RuntimeException  could not reach the billing provider for welcome-email
    …/app/Jobs/ChargeCustomer.php:24                      first seen 27s ago
```

The frame matters: the same exception thrown from two places is two different
problems, and merging them would hide one of them. `Order 4471 failed` and
`Order 8823 failed` are one problem, and are merged.

Retry the whole cluster in one decision. **Ignore** an expected failure so it
stops appearing; **resolve** one you have fixed — and if it happens again it
reopens itself, because declaring something fixed does not make it so. An
ignored issue stays ignored, since that was a decision about noise rather than a
claim of a fix.

## Alerting that does not cry wolf

```php
'rules' => [
    [
        'name' => 'Default queue backing up',
        'connection' => 'redis', 'queue' => 'default',
        'metric' => 'backlog',
        'above' => 5000,
        'recovers_below' => 1000,
        'for' => 3,
        'cooldown' => 900,
    ],
],
```

```php
Schedule::command('yard:check')->everyMinute();
```

Four deliberate constraints, because an alerting system people mute is worse
than none — everyone still believes it is watching:

- **Two thresholds.** A rule that fires and clears at the same number flaps.
- **Consecutive breaches** before firing, so a momentary spike is not an
  incident.
- **A cooldown** once it has spoken, however bad it stays.
- **Silence where it cannot know.** A metric the driver cannot answer is skipped,
  not read as zero, and a failure rate over no jobs is undefined rather than
  healthy — otherwise every quiet night pages someone.

Metrics: `backlog`, `oldest_job_age`, `failure_rate`, `p95_runtime`. Every alert
also fires an `AlertFired` / `AlertRecovered` event, so you can route it anywhere
without Yardmaster knowing how.

## Workers and pause

The **Workers** tab shows what is actually consuming your queues: host, pid,
queues, current job and how long it has been on it, memory, uptime. A worker
that stops heartbeating is shown as **stale** rather than dropped — silence is
the symptom worth seeing, and a worker wedged mid-job goes quiet while still
claiming to be working.

Yardmaster observes workers rather than supervising them. Supervisor and systemd
already do that job well; taking it over would double the surface area for no
gain.

**Pause and resume** work on every driver, because the framework owns them: the
worker checks a cache key before reserving, whatever it is reserving from.
Yardmaster wraps that and audits it rather than reimplementing it.

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
composer require abhishekmenon767/yardmaster
php artisan vendor:publish --tag=yardmaster-migrations
php artisan migrate
```

Pausing and resuming a queue is the one control the framework itself owns, and
it arrived in **Laravel v12.40.0**. On earlier 12.x releases everything else
works and the pause control refuses with a 422 that says why, rather than
reporting a queue as running when the command never took effect.

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

## Updating

```bash
composer update abhishekmenon767/yardmaster
```

No migration or publish step is needed unless a release says otherwise. A
release that changes the schema will say so, and the dashboard refuses to serve
a stale schema rather than filling with quiet gaps — every API route answers
503 naming the tables that are behind.

## Uninstalling

Roll back before removing the package, not after:

```bash
php artisan migrate:rollback --step=5 --pretend   # check first
php artisan migrate:rollback --step=5
composer remove abhishekmenon767/yardmaster
rm -f config/yardmaster.php
rm -f database/migrations/*_create_yard_*_table.php
```

`--step` counts migrations, not tables, so `--step=5` is the five Yardmaster
tables only if nothing else has been migrated since it was installed. That is
what the `--pretend` run is for. If your own migrations came later, target the
files instead:

```bash
php artisan migrate:rollback --path=database/migrations/2026_01_01_000000_create_yard_runs_table.php
```

The order matters. The published migrations resolve their target with
`config('yardmaster.storage.connection')`, and that key has no fallback. Remove
the package first and it resolves to `null`, so an installation that pointed
telemetry at its own connection would drop from the *default* database instead
— finding nothing, reporting success, and leaving the real tables behind. If
that has already happened, drop them by hand:

```sql
DROP TABLE IF EXISTS yard_runs, yard_buckets, yard_actions, yard_issues, yard_workers;
```

Two things the commands above do not reach: the scheduled `yard:trim` entry,
which will fail every fifteen minutes once the command is gone, and any
`viewYardmaster` / `manageYardmaster` gate definitions, which are harmless but
dead.

To switch it off without uninstalling, set `YARDMASTER_ENABLED=false`. That
stops every listener and leaves the recorded history intact.

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

## Upgrading

Yardmaster ships schema changes as migrations. After any upgrade:

```bash
php artisan vendor:publish --tag=yardmaster-migrations --force
php artisan migrate
```

If you forget, the dashboard tells you rather than going quiet: a missing table
answers `Yardmaster has not been migrated yet`, and a table that exists but is
behind the code answers `Yardmaster's schema is out of date`. Both name the
tables. This matters because recording is deliberately failure-tolerant — a
schema that has drifted means jobs keep succeeding while telemetry silently
stops, and that is a bad way to find out.

## Roadmap

**1.0 is complete.** What is deliberately not in it:

- **A Beanstalkd adapter.** It resolves to the null adapter — full recorded
  history, live controls correctly disabled. Shipping an adapter that had never
  run against a real server would be exactly the unverified claim this package
  exists to avoid.
- **Process supervision and autoscaling.** Supervisor and systemd already do
  that job well. Yardmaster observes workers instead.
- **Multi-application aggregation.** One application per dashboard for now.

Static analysis passes at **level 9**. Config values, database columns and
decoded payloads genuinely are of unknown type at the boundary; `Support\Cast`
narrows them with an explicit fallback rather than a bare cast that hides what
happens when one is null.

## Contributing

Pull requests welcome. `composer check` runs Pint, PHPStan and the suite — the
same three gates CI runs. A fourth CI job rebuilds `dist/` and fails if the
committed bundle has drifted from its source.

Adding a queue driver means adding an adapter class and a capability map, then
running `tests/Contracts/AdapterContract.php` against it. If that is ever not
enough, the abstraction has leaked and the adapter is the wrong place to fix it.

## Licence

MIT. See [LICENSE.md](LICENSE.md).
