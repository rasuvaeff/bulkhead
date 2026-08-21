# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.3 — 2026-08-21

- Fix a slot leak: the `onAccepted` observer callback ran after acquire but outside the `try/finally` that guarantees `release()`, so a throwing observer (unreachable metrics backend, a `TypeError` in the closure) left the slot occupied — until lease expiry on Redis/APCu, and **permanently** with `InMemoryBulkheadStore` (which ignores the lease). The callback now runs inside the `try`.
- Document the release-path failure semantics: a `release()` exception masks the callback's exception (reachable via `getPrevious()`, which PHP chains automatically) and loses a successful result — a store exception from `call()` means "outcome unknown", never "did not happen". Pinned by tests.
- Docs: fix the name-pattern anchor shown in README.md/README.ru.md/llms.txt to the `\z` the code actually uses (`$` matches before a trailing newline); document that `keyPrefix` is trusted configuration (not validated, unlike `name`) and the token trust model (CSPRNG tokens protect against accidents, not adversaries — the trust boundary is Redis access itself).

## 1.1.2 — 2026-08-21

- Document that the store is a hard dependency: if Redis/APCu is unreachable, `call()` fails closed (throws) rather than admitting the call unboundedly. Callers who only caught `BulkheadFullException` could be surprised by an uncaught `Predis\Connection\ConnectionException` (or equivalent) on a store outage.
- Raise `rasuvaeff/property-testing-testo` to `^0.6`.

## 1.1.1 — 2026-07-25

- Reject trailing newlines in bulkhead-name validation: anchor
  `SharedBulkhead::NAME_PATTERN` with `\z` instead of `$` (PCRE `$` matches
  before a trailing `\n`, which let `"<name>\n"` pass and become the
  Redis/APCu key).

## 1.1.0 — 2026-07-25

- Ship an AI agent skill (`resources/skills/rasuvaeff-bulkhead/SKILL.md` +
  `extra.skills` in composer.json): projects using the `llm/skills` Composer
  plugin get the skill synced into `.agents/skills/` automatically on install.
- Bump `rasuvaeff/property-testing` dev dependency to `^2.6`.
- Property-generator methods in tests are now `public static` (private ones are
  removed by rector's `RemoveUnusedPrivateMethodRector` — they are only called
  via reflection).

## 1.0.1 — 2026-07-03

- `PredisScriptRunner` issues EVALSHA/EVAL through the typed
  `createCommand()`/`executeCommand()` API instead of magic `__call` — the
  `@method` annotations for `eval`/`evalsha` are not resolvable by psalm across
  every supported predis release (broke the prefer-lowest CI job).

## 1.0.0 — 2026-07-03

- Initial release: `SharedBulkhead` cross-process concurrency limiter with
  `RedisBulkheadStore` (multi-host, sorted set + Lua, EVALSHA-first script
  runners for predis and `ext-redis`), `ApcuBulkheadStore` (single-host,
  APCu spinlock) and `InMemoryBulkheadStore` (tests/CLI).
- Optional `pollJitter` desynchronizes waiters polling for a freed slot.
- `onAccepted`/`onRejected` callbacks receive the bulkhead name and the time
  waited for a slot.
- All Redis clients are optional dependencies (`suggest`): install
  `predis/predis` or `ext-redis` only if you use `RedisBulkheadStore`.
