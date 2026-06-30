# Examples

Runnable scripts demonstrating `rasuvaeff/bulkhead`.

```bash
composer install
php examples/basic.php
```

| Script | Shows | Needs server? |
|---|---|---|
| `basic.php` | In-memory store: a call, available slots, fast-fail when full | no |
| `redis.php` | Cross-process limiting via `RedisBulkheadStore` | yes |
| `apcu.php` | Single-host cross-process limiting via `ApcuBulkheadStore` | no (needs `ext-apcu`) |

`redis.php` self-skips with a message unless `REDIS_HOST` is set:

```bash
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 php examples/redis.php
```

`apcu.php` self-skips with a message unless `ext-apcu` is loaded and enabled
for the CLI SAPI (`apc.enable_cli=1`):

```bash
php -d apc.enable_cli=1 examples/apcu.php
```
