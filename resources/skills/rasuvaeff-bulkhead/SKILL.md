---
name: rasuvaeff-bulkhead
description: >-
  Cap simultaneous calls to a dependency across a whole PHP-FPM worker pool with
  rasuvaeff/bulkhead — SharedBulkhead over a shared counter in Redis
  (RedisBulkheadStore, multi-host) or APCu (ApcuBulkheadStore, single host),
  with BulkheadFullException on rejection. Use when writing, reviewing or
  debugging concurrency limiting, downstream overload protection or
  BulkheadFullException handling in a project that has this package installed.
---

# rasuvaeff/bulkhead

Cross-process concurrency limiter for PHP-FPM: `SharedBulkhead` admits at most
`maxConcurrent` simultaneous `call()`s counted across every worker that shares
a `BulkheadStore`. Namespace `Rasuvaeff\Bulkhead`.

## Safety rules — verify these on every change

1. **Pick the store that matches the deployment.** `RedisBulkheadStore` is the
   only cross-host limiter; `ApcuBulkheadStore` coordinates workers on ONE host
   (APCu shared memory doesn't span machines); `InMemoryBulkheadStore` is
   single-process (tests/CLI) — never a pool limiter in FPM.

2. **`lease` must exceed the longest possible callback runtime** (downstream
   timeout + margin). The lease TTL is what reclaims a dead worker's slot; if
   the callback outlives it, the slot is reclaimed mid-call and real
   concurrency exceeds `maxConcurrent`.

3. **Never hold a permit past the call.** `SharedBulkhead::call()` releases in
   `finally` for you — prefer it. If you use `BulkheadStore::tryAcquire()`
   directly, `release($name, $token)` must sit in a `finally` block, or the
   slot stays taken until the lease expires.

4. **Rejection, not a queue.** `maxWait: Duration::zero()` fast-fails; a
   non-zero `maxWait` polls (approximate budget, NOT FIFO — waiters can
   starve). Always catch `BulkheadFullException` and degrade gracefully; don't
   build an unbounded retry loop around it.

5. **Acquire must stay atomic.** The Redis Lua script (prune expired → check
   `ZCARD` < max → `ZADD`, TTL never shrinks) must remain ONE script; APCu gets
   the same guarantee from an `apcu_add` spinlock (`=== true`, not `!== true`).
   Splitting either reintroduces the check-then-incr race the package exists
   to prevent.

6. **`name` must match `/^[A-Za-z0-9_.:-]+$/`** (it becomes a Redis/APCu key),
   and on Redis `availableSlots()`/`activeCount()` WRITE (they prune expired
   members) — don't point the store at a read-only replica.

## Canonical usage

```php
use Predis\Client;
use Rasuvaeff\Bulkhead\{BulkheadFullException, RedisBulkheadStore, SharedBulkhead};
use Rasuvaeff\Bulkhead\Redis\PredisScriptRunner;
use Rasuvaeff\Duration\Duration;

$bulkhead = new SharedBulkhead(
    name: 'legacy-api',
    maxConcurrent: 10,
    store: new RedisBulkheadStore(new PredisScriptRunner(new Client(['host' => '127.0.0.1']))),
    lease: Duration::seconds(5),          // > longest callback runtime
    maxWait: Duration::millis(200),       // Duration::zero() = fast-fail
);

try {
    $value = $bulkhead->call(static fn(): string => callDownstream());
} catch (BulkheadFullException $e) {
    // $e->name, $e->maxConcurrent — degrade gracefully
}
```

## Full API

The complete reference — every constructor option (`pollInterval`, `pollJitter`,
`onAccepted`/`onRejected`), `PhpRedisScriptRunner`, APCu/in-memory setup and the
`BulkheadStore` contract — ships with the package: read
`vendor/rasuvaeff/bulkhead/llms.txt` before guessing a method name.
