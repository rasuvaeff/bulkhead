# AGENTS.md — bulkhead

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/bulkhead` is a cross-process concurrency limiter for PHP-FPM
(namespace `Rasuvaeff\Bulkhead`). `SharedBulkhead` admits at most
`maxConcurrent` simultaneous `call()`s, counted across every process that shares
a `BulkheadStore`. Two cross-process backends: `RedisBulkheadStore` (sorted set +
Lua, atomic acquire, multi-host; client-agnostic via `BulkheadScriptRunner` —
`Redis\PredisScriptRunner` for predis, `Redis\PhpRedisScriptRunner` for
`ext-redis`, both EVALSHA-first) and `ApcuBulkheadStore` (APCu spinlock over
`apcu_add`, single-host only); `InMemoryBulkheadStore` is single-process only
(tests/CLI). Lease/wait values are `rasuvaeff/duration` `Duration`s.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
   (The untyped Redis-client boundaries are isolated to
   `Redis\PredisScriptRunner`/`Redis\PhpRedisScriptRunner`, which cast the
   `mixed` reply once. PredisScriptRunner must go through
   `createCommand()`/`executeCommand()` — NOT the magic `eval()`/`evalsha()`
   `@method`s, which psalm cannot resolve on every predis release the
   prefer-lowest job installs.)
3. **`lease` must outlive the work it protects, and acquire must stay atomic.**
   The lease TTL is what reclaims a dead worker's slot; if a callback can run
   longer than its lease, the slot is reclaimed mid-call and concurrency exceeds
   `maxConcurrent`. Redis's acquire Lua script (prune expired → check `ZCARD` <
   max → `ZADD`) must remain a single atomic script — splitting it reintroduces
   the check-then-incr race the package exists to prevent. APCu has no
   server-side scripting, so `ApcuBulkheadStore` gets the same atomicity from an
   `apcu_add`-based spinlock around the whole read-check-write — that comparison
   (`=== true`, not `!== true`) is the one line in this package proven to break
   mutual exclusion under real concurrency if flipped (see
   `neverExceedsMaxConcurrentUnderRealForkContention`); never weaken it without
   re-running that test against forked processes, not just sequentially.
4. **Preserve the public contract.** Update README + llms.txt + tests with any
   API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```

`rasuvaeff/duration` is a normal Packagist dependency (`^1.0`) — no path-repo,
no monorepo-root mount needed.

### Integration & mutation need Redis + APCu

Integration tests (`tests/Integration`) are excluded from the Unit suite and
self-skip unless `REDIS_HOST` is set (Redis) / `ApcuBulkheadStore::isAvailable()`
is true (APCu), so `composer build` is green with neither. But
`RedisBulkheadStore`/`ApcuBulkheadStore` are **only** covered by the Integration
suite (`#[Covers]` on `RedisBulkheadIntegrationTest`/`ApcuBulkheadIntegrationTest`
— `#[CoversNothing]` would zero their mutation attribution entirely, not just
deprioritize it) — run mutation with both reachable, or MSI drops:

```bash
docker run -d --name bh-redis -p 6379:6379 redis:7-alpine
docker run --rm --network host -v "$PWD":/repo -w /repo/bulkhead -e REDIS_HOST=127.0.0.1 \
  --entrypoint sh <pcov+apcu-image> -c 'git config --global --add safe.directory "*"; composer mutation'
docker rm -f bh-redis
```

The pcov image needs `apcu` + `pcntl` + `redis` extensions and `apc.enable_cli=1`
(`pecl install pcov apcu redis`, `docker-php-ext-install pcntl`, then an ini file
with `apc.enable_cli=1` — see `.github/workflows/build.yml`'s `coverage` job for
the exact extension list). `redis` backs `PhpRedisIntegrationTest`, which is the
only coverage `PhpRedisScriptRunner` gets — run mutation without `ext-redis` and
its mutants all escape. `pcntl` backs
`ApcuBulkheadIntegrationTest::neverExceedsMaxConcurrentUnderRealForkContention`,
which forks real processes behind a rendezvous barrier (`apcu_inc`/poll) to
race for the same slots — a single-process test cannot distinguish a correct
`apcu_add` compare-and-set from one whose success/failure branches are
swapped, since the lock key gets set either way. Without the barrier,
`pcntl_fork()`'s natural stagger between children can exceed the critical
section's duration and the race window is simply missed (flaky-clean). The
assertion measures **peak** concurrency — a shared `apcu_inc` counter raised
after acquire and lowered *before* release, so its interval nests inside the
hold and the recorded peak can only undercount, never overcount. Counting
total acquisitions instead (what this test did until #22) reads legitimate
slot reuse as a violation the moment the pack cycles through the lock faster
than the hold time, which is exactly what a CPU-saturated runner produces —
reproducible as 3/3 red under `docker run --cpus=1` on unmutated code, where
the peak-based assertion is 5/5 green. Keep the lease (60s) far longer than
the hold (200ms) for a related reason: a lease expiring mid-hold would let the
store legitimately reclaim a live worker's slot, over-admitting for real. Don't
shrink the worker count/hold time, or swap the dec and the release, without
re-verifying against a hand-mutated `lock()` first. If it ever goes red again,
the remaining theoretical route on correct code is the class docblock's known
compromise — a lock holder descheduled past `LOCK_TTL_SECONDS` (1s) loses its
lock to a successor — not the total-vs-peak miscount, which is closed.

CI runs the Integration suite and mutation in the `coverage` job, which provides
a `redis:7-alpine` service container + `REDIS_HOST`, plus `apcu`/`pcntl`/`redis`.

## Invariants & gotchas

- `lease`/`maxWait`/`pollInterval` are `Duration`; `maxWait = Duration::zero()`
  is fast-fail. The wait loop accumulates `Sleeper` time and clamps the last
  sleep with `Duration::minus` (saturating), so the budget is bounded but
  approximate (per-attempt Redis latency is not counted).
- `name` is validated (`/^[A-Za-z0-9_.:-]+\z/`) because it becomes a Redis/APCu key.
- `pollJitter` (0.0..1.0) randomizes each poll sleep within ±(jitter × pollInterval)
  with a **1µs floor** — the `max(1, ...)` in `jitteredPollInterval` is what stops a
  zero-length sleep from spinning the wait loop without consuming budget; don't
  remove it. `onAccepted`/`onRejected` receive `(string $name, Duration $waited)`.
- The acquire Lua script must never *shrink* the key TTL (`PTTL` guard before
  `PEXPIRE`): a shorter lease on the same name would otherwise expire the whole
  sorted set under longer-lease members
  (`shorterLeaseDoesNotExpireLongerLeasedSlots` covers this).
- **All Redis clients are optional deps.** `predis/predis` lives in `require-dev`
  + `suggest` (NOT `require`) — an APCu-only or in-memory consumer must not pull
  a Redis client; `ext-redis` is `suggest`-only likewise. `ext-apcu` is
  `suggest`-only (not `require`) — installing bulkhead must not force the
  extension on consumers who only use Redis. Psalm resolves `apcu_*` signatures
  from its bundled `jetbrains/phpstorm-stubs` call map regardless of whether the
  extension is loaded; the `\Redis` class comes from psalm's bundled
  `redis.phpstub` via `<enableExtensions><extension name="redis"/>` in
  `psalm.xml` — so static analysis needs no extensions installed.
  `composer-require-checker.json` whitelists the `apcu_*` symbols plus
  `Predis\ClientInterface`, `Predis\Response\ServerException` and `Redis` (no
  required package declares them). `property-testing` (dev) needs
  `ext-mbstring` → CI `extensions: json, mbstring`.
- Mutation `minMsi` 86 is the honest floor for what infection's coverage-based
  test selection can see; survivors are documented per-mutant in
  `infection.json5` (equivalent/performance-only mutants, plus one targeted
  `Identical` ignore on `ApcuBulkheadStore::lock` for a mutant that **is**
  killed — verified by hand-mutation — but escapes infection's automated
  selection because pcov coverage doesn't propagate out of `pcntl_fork()`'d
  children). The `max(0,x)`->`max(-1,x)` survivors are provably equivalent
  (`x` is never negative) but occasionally register as "killed" by run-to-run
  noise rather than any real test — don't read a 87% run as room to raise the
  gate back up. Do not chase the documented ones with `@psalm-suppress` or
  raise `minMsi` without first checking whether a "new" survivor is actually
  one of the documented ones losing its `ignore` scope after a refactor.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- `examples/` is part of the public contract: keep scripts runnable (`redis.php`
  self-skips without `REDIS_HOST`) and update `examples/README.md` on changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment. Never revert to
  floating `@vN` tags; updates go through Dependabot. Workflows carry
  `permissions: { contents: read }` and `persist-credentials: false` on every
  checkout. Verify with `zizmor --persona=auditor .github/` — no `unpinned-uses`,
  `excessive-permissions`, or `artipacked` findings. (The `redis` service
  container image is tag-pinned, mirroring `clickhouse-toolkit`.)

## When you finish

- Update `README.md`, `llms.txt` (and `examples/` if usage changed); update
  `CHANGELOG.md` when releasing.
- Re-run `composer build` (green with no Redis) **and** the Integration suite +
  mutation against a real Redis. Paste the output.
