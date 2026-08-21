# rasuvaeff/bulkhead

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/bulkhead/v)](https://packagist.org/packages/rasuvaeff/bulkhead)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/bulkhead/downloads)](https://packagist.org/packages/rasuvaeff/bulkhead)
[![Build](https://github.com/rasuvaeff/bulkhead/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/bulkhead/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/bulkhead/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/bulkhead/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/bulkhead/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/bulkhead/php)](https://packagist.org/packages/rasuvaeff/bulkhead)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[Русская версия](README.ru.md)

Cross-process concurrency limiter (bulkhead) for PHP-FPM. Caps the number of
**simultaneous** calls to a fragile dependency across the **whole worker pool**,
so a spike can't pile every worker onto a downstream that only tolerates a few
connections. Over the limit, calls fast-fail (or wait briefly) instead of
cascading the failure.

A counter shared in Redis or APCu is the coordination point: in shared-nothing
FPM the limit has to live outside the process, because each request runs in
its own worker. Complements a circuit breaker (which decides *whether* to
try) — a bulkhead decides *how many at once*.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer plugin also get this package's agent skill synced into `.agents/skills/` automatically on install.

## Requirements

- PHP 8.3+
- [`rasuvaeff/duration`](https://github.com/rasuvaeff/duration) for the typed lease/wait values
- For multi-host cross-process limiting (`RedisBulkheadStore`): a reachable Redis
  server plus **one** Redis client — [`predis/predis`](https://github.com/predis/predis)
  ^2.2 (pure-PHP, `PredisScriptRunner`) or `ext-redis` (`PhpRedisScriptRunner`).
  Both are optional dependencies; install the one you use.
- `ext-apcu` for single-host cross-process limiting (`ApcuBulkheadStore`) — optional, not a hard dependency

## Installation

```bash
composer require rasuvaeff/bulkhead

# for RedisBulkheadStore with the pure-PHP client:
composer require predis/predis
```

## Usage

```php
use Predis\Client;
use Rasuvaeff\Bulkhead\BulkheadFullException;
use Rasuvaeff\Bulkhead\Redis\PredisScriptRunner;
use Rasuvaeff\Bulkhead\RedisBulkheadStore;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\Duration\Duration;

$bulkhead = new SharedBulkhead(
    name: 'legacy-api',
    maxConcurrent: 10,
    store: new RedisBulkheadStore(new PredisScriptRunner(new Client(['host' => '127.0.0.1']))),
    lease: Duration::seconds(5),    // a slot is auto-reclaimed after this if not released
    maxWait: Duration::millis(200), // wait up to 200ms for a slot; Duration::zero() = fast-fail
);

try {
    $result = $bulkhead->call(static fn(): string => callDownstream());
} catch (BulkheadFullException $e) {
    // All slots busy — degrade gracefully instead of hammering the dependency.
}
```

With `ext-redis` instead of predis:

```php
use Rasuvaeff\Bulkhead\Redis\PhpRedisScriptRunner;

$redis = new \Redis();
$redis->connect('127.0.0.1');
$store = new RedisBulkheadStore(new PhpRedisScriptRunner($redis));
```

Optional knobs:

```php
$bulkhead = new SharedBulkhead(
    name: 'legacy-api',
    maxConcurrent: 10,
    store: $store,
    lease: Duration::seconds(5),
    maxWait: Duration::millis(200),
    pollInterval: Duration::millis(50), // polling granularity while waiting
    pollJitter: 0.5,                    // randomize each poll sleep ±50% so waiters
                                        // don't stampede a freed slot in lockstep
    onAccepted: static fn(string $name, Duration $waited) => $metrics->timing("bulkhead.$name.wait", $waited->toMillis()),
    onRejected: static fn(string $name, Duration $waited) => $metrics->increment("bulkhead.$name.rejected"),
);
```

### Public API

| Type | Description |
|---|---|
| `Bulkhead` | Interface: `call(callable): mixed`, `availableSlots(): int` |
| `SharedBulkhead` | Limits concurrency using a `BulkheadStore`; fast-fails or waits up to `maxWait`; exposes `name()`, `maxConcurrent()` |
| `BulkheadStore` | Backing store: `tryAcquire`, `release`, `activeCount` |
| `RedisBulkheadStore` | Multi-host cross-process store; sorted-set + Lua, atomic acquire, lease TTL |
| `ApcuBulkheadStore` | Single-host cross-process store; APCu spinlock, atomic acquire, lease TTL |
| `InMemoryBulkheadStore` | Single-process store (tests / CLI); does not coordinate across processes |
| `BulkheadScriptRunner` | Typed seam over a Redis script call (implement for another client) |
| `Redis\PredisScriptRunner` | predis-backed `BulkheadScriptRunner`; EVALSHA with EVAL fallback |
| `Redis\PhpRedisScriptRunner` | `ext-redis`-backed `BulkheadScriptRunner`; EVALSHA with EVAL fallback |
| `BulkheadFullException` | Thrown when no slot is available within `maxWait`; carries `name`, `maxConcurrent` |
| `Sleeper\SleeperInterface` | Wait strategy while polling; `SystemSleeper`, `FakeSleeper` |

### Sizing the knobs

- **`maxConcurrent`** — what the *downstream* tolerates, not what the pool can
  send. If the dependency handles ~10 concurrent connections comfortably and you
  run 3 app hosts sharing one Redis, `maxConcurrent: 10` caps all hosts
  together. It must be smaller than your FPM worker count to mean anything —
  with 50 workers and `maxConcurrent: 100` the bulkhead never engages.
- **`lease`** — strictly greater than the worst-case callback runtime, in
  practice: downstream timeout + a safety margin. Too short and slots are
  reclaimed mid-call (limit overshoots); too long and a crashed worker's slot
  stays occupied for the whole lease (limit undershoots). If the callback is an
  HTTP call with a 5s timeout, `lease: Duration::seconds(10)` is a sane start.
- **`maxWait`** — how long a request may queue for a slot. `Duration::zero()`
  fast-fails (shed load immediately); anything longer trades latency for a
  lower rejection rate. Keep it well under your own request timeout.
- **`pollJitter`** — set it to `0.1`–`0.5` when many workers may wait at once,
  so a freed slot isn't stampeded by every waiter on the same 50ms tick.

### How the limit holds across workers

`RedisBulkheadStore` keeps a sorted set per bulkhead: each active slot is a
member scored with its lease-expiry. `tryAcquire` runs a single Lua script that
prunes expired members, checks the cardinality against the limit, and adds a
member — so the check and the add are atomic and two workers cannot both slip
past the limit. A worker that dies mid-call leaks nothing: its member's lease
score passes and the slot is reclaimed on the next acquire.

`ApcuBulkheadStore` keeps a `token => expiresAt` array per bulkhead in one APCu
entry. APCu has no server-side scripting, so atomicity comes from a spinlock
instead: `tryAcquire`/`release` take a short-lived APCu key (`apcu_add` as
create-if-absent) before reading or writing the slot array, and the lock itself
carries a TTL so a worker that dies while holding it doesn't deadlock the
others. Only coordinates workers on the **same host** — APCu's shared memory
doesn't span machines; use `RedisBulkheadStore` for a pool spread across hosts.

## Security

- `name` is validated against `/^[A-Za-z0-9_.:-]+$/` and becomes part of the
  Redis/APCu key — untrusted names are rejected, not interpolated blindly.
- Values flow into the Lua script as bound `ARGV`, never string-concatenated.
- The package opens no network connections itself; you supply the Redis client.
- **The store is a hard dependency: unreachable fails closed, not open.** If
  the configured Redis client can't connect, or the store otherwise errors,
  `call()` does **not** silently admit the call — it throws. `call()` can
  therefore throw more than `BulkheadFullException`: a Redis connection
  failure surfaces as `Predis\Connection\ConnectionException` (or the
  equivalent from your client / `\RuntimeException` from
  `PhpRedisScriptRunner`), uncaught by the package. If you only `catch
  (BulkheadFullException)`, an outage of the store itself will propagate past
  that catch block.

## Caveats

- **`lease` must exceed the longest expected callback runtime.** If a call runs
  longer than its lease, the store reclaims the slot mid-execution and another
  worker can acquire it — concurrency then briefly exceeds `maxConcurrent`. Size
  the lease above your downstream timeout.
- `maxWait` is an approximate, poll-based bound (default 50ms granularity): the
  per-attempt store round-trip is not counted, so real wall time can slightly
  exceed it.
- **Waiting is not FIFO.** Waiters poll; whoever polls right after a release
  wins the slot. Under sustained overload a waiter can starve past `maxWait`
  and be rejected while later arrivals get through.
- `availableSlots()` / `activeCount()` on Redis **write** (they prune expired
  members), so they can't be pointed at a read-only replica.
- `InMemoryBulkheadStore` is single-process only — it does **not** limit the FPM
  pool. Use it for tests and CLI tools.
- `ApcuBulkheadStore` only limits workers on the **same machine**. A pool spread
  across multiple hosts needs `RedisBulkheadStore`. Two sharp edges of the APCu
  spinlock:
  - `tryAcquire`/`release` spin up to ~100ms (configurable via
    `lockMaxAttempts`/`lockRetryMicros`) for the internal lock. A failed
    `tryAcquire` spin reports "full"; a failed `release` spin leaves the slot to
    expire with its lease.
  - APCu has no compare-and-delete, so `unlock` can't verify ownership: a holder
    stalled past the 1s lock TTL inside the microsecond-sized critical section
    could delete a successor's lock. Accepted as negligible for a critical
    section this small; use Redis if that guarantee matters to you.

## Examples

See [examples/](examples/) for runnable scripts.

| Script | Shows | Needs server? |
|---|---|---|
| `basic.php` | In-memory store, fast-fail when full | no |
| `redis.php` | Cross-process limiting with Redis | yes (`REDIS_HOST`) |
| `apcu.php` | Single-host cross-process limiting with APCu | no (needs `ext-apcu`) |

## Development

No PHP/Composer on the host — run in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```

Integration tests need a Redis server (self-skip unless `REDIS_HOST` is set),
`ext-apcu` (self-skip via `ApcuBulkheadStore::isAvailable()`) and `ext-redis`
(self-skip via `extension_loaded('redis')`); the base `composer:2` image has
none of them, so run the suite in an image carrying `apcu`, `pcntl` and `redis`
(plus `apc.enable_cli=1`):

```bash
docker run -d --name bh-redis -p 6379:6379 redis:7-alpine
docker run --rm --network host -v "$PWD":/app -w /app -e REDIS_HOST=127.0.0.1 \
  <php-image-with-apcu-pcntl-redis> vendor/bin/testo --suite=Integration
docker rm -f bh-redis
```

## License

[BSD-3-Clause](LICENSE.md)
