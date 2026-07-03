# Examples

Runnable scripts demonstrating `rasuvaeff/bulkhead`.

```bash
composer install
php examples/basic.php
```

| Script | Shows | Needs server? |
|---|---|---|
| `basic.php` | In-memory store: a call, available slots, fast-fail when full | no |
| `redis.php` | Cross-process limiting via `RedisBulkheadStore` + predis | yes |
| `phpredis.php` | Same store via `ext-redis`, plus `pollJitter` and `onAccepted` | yes (needs `ext-redis`) |
| `apcu.php` | Single-host cross-process limiting via `ApcuBulkheadStore` | no (needs `ext-apcu`) |

`redis.php` and `phpredis.php` self-skip with a message unless `REDIS_HOST` is
set (`phpredis.php` also self-skips without `ext-redis`):

```bash
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 php examples/redis.php
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 php examples/phpredis.php
```

`apcu.php` self-skips with a message unless `ext-apcu` is loaded and enabled
for the CLI SAPI (`apc.enable_cli=1`):

```bash
php -d apc.enable_cli=1 examples/apcu.php
```
