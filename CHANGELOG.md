# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
