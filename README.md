# rasuvaeff/bulkhead

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/bulkhead/v)](https://packagist.org/packages/rasuvaeff/bulkhead)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/bulkhead/downloads)](https://packagist.org/packages/rasuvaeff/bulkhead)
[![Build](https://github.com/rasuvaeff/bulkhead/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/bulkhead/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/bulkhead/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/bulkhead/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/bulkhead/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/bulkhead/php)](https://packagist.org/packages/rasuvaeff/bulkhead)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

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

## Requirements

- PHP 8.3+
- [`rasuvaeff/duration`](https://github.com/rasuvaeff/duration) for the typed lease/wait values
- [`predis/predis`](https://github.com/predis/predis) ^2.2 (pure-PHP Redis client; no extension required)
- A reachable Redis server for multi-host cross-process limiting (`RedisBulkheadStore`)
- `ext-apcu` for single-host cross-process limiting (`ApcuBulkheadStore`) — optional, not a hard dependency

## Installation

```bash
composer require rasuvaeff/bulkhead
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

### Public API

| Type | Description |
|---|---|
| `Bulkhead` | Interface: `call(callable): mixed`, `availableSlots(): int` |
| `SharedBulkhead` | Limits concurrency using a `BulkheadStore`; fast-fails or waits up to `maxWait` |
| `BulkheadStore` | Backing store: `tryAcquire`, `release`, `activeCount` |
| `RedisBulkheadStore` | Multi-host cross-process store; sorted-set + Lua, atomic acquire, lease TTL |
| `ApcuBulkheadStore` | Single-host cross-process store; APCu spinlock, atomic acquire, lease TTL |
| `InMemoryBulkheadStore` | Single-process store (tests / CLI); does not coordinate across processes |
| `BulkheadScriptRunner` | Typed seam over a Redis script call (implement for phpredis) |
| `Redis\PredisScriptRunner` | predis-backed `BulkheadScriptRunner` |
| `BulkheadFullException` | Thrown when no slot is available within `maxWait` |
| `Sleeper\SleeperInterface` | Wait strategy while polling; `SystemSleeper`, `FakeSleeper` |

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

## Caveats

- **`lease` must exceed the longest expected callback runtime.** If a call runs
  longer than its lease, the store reclaims the slot mid-execution and another
  worker can acquire it — concurrency then briefly exceeds `maxConcurrent`. Size
  the lease above your downstream timeout.
- `maxWait` is an approximate, poll-based bound (default 50ms granularity): the
  per-attempt store round-trip is not counted, so real wall time can slightly
  exceed it.
- `InMemoryBulkheadStore` is single-process only — it does **not** limit the FPM
  pool. Use it for tests and CLI tools.
- `ApcuBulkheadStore` only limits workers on the **same machine**. A pool spread
  across multiple hosts needs `RedisBulkheadStore`.

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

Integration tests need a Redis server (self-skip unless `REDIS_HOST` is set)
and `ext-apcu` (self-skip via `ApcuBulkheadStore::isAvailable()`); the base
`composer:2` image has neither, so build a PHP image with both first:

```bash
docker run -d --name bh-redis -p 6379:6379 redis:7-alpine
docker run --rm --network host -v "$PWD":/app -w /app -e REDIS_HOST=127.0.0.1 \
  composer:2 vendor/bin/testo --suite=Integration
docker rm -f bh-redis
```

## License

[BSD-3-Clause](LICENSE.md)
