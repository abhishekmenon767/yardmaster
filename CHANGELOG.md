# Changelog

All notable changes to `abhishekmenon767/yardmaster` are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.1

### Fixed

- **The queue list no longer fatals on Laravel below v12.40.** `QueueManager`
  gained `pause()`, `resume()` and `isPaused()` in v12.40.0, but the package
  requires `^12.0` and guarded the calls with `instanceof QueueManager` — true
  on every 12.x, because only the methods are new. The call fell through
  `__call` to the connection and died with "Call to undefined method
  `Illuminate\Queue\DatabaseQueue::isPaused()`". Since `index()` calls
  `isPaused()` outside any rescue boundary, `GET /api/v1/queues` returned a 500
  on Laravel 12.0 through 12.39. The methods are now detected rather than the
  class, and pausing refuses with a 422 naming the required version instead of
  reporting a queue as paused when the command never reached the framework.
- The workbench no longer pins its database to one developer's home directory,
  which left `testbench serve` reporting an unmigrated schema on any other
  checkout.

### Changed

- `package-lock.json` is committed, so `npm ci` can build `dist/` reproducibly.
- Dev dependencies allow pest 4, which is required to test against Laravel 13.

## 1.0.0

First stable release.

### Telemetry

- One row per **job attempt**, on every queue driver, recorded from framework
  events only — there is no driver conditional anywhere in the recorder.
- Wait time measured from dispatch rather than from the worker picking the job
  up, via metadata injected into the payload.
- Parent linkage for jobs dispatched inside other jobs, so a fan-out reads as a
  tree.
- Automatic Eloquent model tags (`App\Models\Order:4471`).
- Pre-aggregated minute, hour and day rollups with log-scale latency histograms,
  so percentiles survive the raw rows being trimmed away.
- Payload redaction on by default; the serialized command is never stored.

### Live introspection and control

- Capability-negotiated adapters for `database`, `redis` and `sqs`; every other
  driver resolves to a null adapter with full history and controls correctly
  disabled.
- Depth, peek, delete, promote and purge, each gated on what the driver can
  honestly do — an unsupported control is disabled with the reason, and the API
  refuses it with the same wording.
- Pause and resume on every driver, wrapping the framework's own mechanism.
- Every state-changing operation audited with actor, IP and affected count.

### Operations

- Failures grouped into **issues** by exception class, normalised message and
  first application frame. Retry a cluster in one decision; ignore or resolve,
  and a resolved issue reopens itself if it recurs.
- Alerting on backlog, oldest job age, failure rate and p95 runtime, with two
  thresholds, consecutive-breach counting and a cooldown.
- Worker roster from throttled heartbeats, with stale detection.

### Scale

- Redis stream ingest plus the `yard:work` drainer, `yard:restart` for deploys,
  and `--once` for schedulers with no long-lived process.
- Sampling, with counts scaled back up and marked approximate rather than
  silently under-reported.
- Octane request scoping.
- Measured overhead: 0.19 ms per job batched, 0.12 ms on Redis ingest.

### Interface

- Compiled Vue 3 dashboard served from the package — no npm step for the
  application, no CDN, and no `unsafe-inline` exception needed.
- Versioned JSON API with server-sent-event live updates.
- Two authorization gates: `viewYardmaster` to read, `manageYardmaster` to
  change queue state. Neither is granted outside `local` until you define it.
