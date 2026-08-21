# rasuvaeff/bulkhead

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/bulkhead/v)](https://packagist.org/packages/rasuvaeff/bulkhead)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/bulkhead/downloads)](https://packagist.org/packages/rasuvaeff/bulkhead)
[![Build](https://github.com/rasuvaeff/bulkhead/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/bulkhead/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/bulkhead/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/bulkhead/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/bulkhead/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/bulkhead/php)](https://packagist.org/packages/rasuvaeff/bulkhead)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[English version](README.md)

Межпроцессный ограничитель параллелизма (bulkhead) для PHP-FPM. Ограничивает число
**одновременных** вызовов хрупкой зависимости во **всём пуле воркеров**: всплеск
нагрузки не может заставить каждого воркера штурмовать downstream, который держит
лишь несколько соединений. При превышении лимита вызовы быстро падают (fast-fail)
либо кратко ждут — вместо того, чтобы каскадно распространять сбой.

Точка координации — общий счётчик в Redis или APCu: в shared-nothing-модели FPM
лимит должен жить вне процесса, потому что каждый запрос идёт в своём воркере.
Дополняет circuit breaker (который решает, *стоит ли* пробовать) — bulkhead решает,
*сколько одновременно*.

> Используете AI-ассистента? В [llms.txt](llms.txt) — компактный API-справочник,
> которым можно поделиться с моделью.
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills) дополнительно получают agent-скилл этого пакета в `.agents/skills/` автоматически при установке.

## Требования

- PHP 8.3+
- [`rasuvaeff/duration`](https://github.com/rasuvaeff/duration) для типизированных значений lease/wait
- Для межхостового ограничения (`RedisBulkheadStore`): доступный Redis-сервер
  плюс **один** Redis-клиент — [`predis/predis`](https://github.com/predis/predis)
  ^2.2 (чистый PHP, `PredisScriptRunner`) либо `ext-redis` (`PhpRedisScriptRunner`).
  Обе зависимости опциональны; ставьте тот, что используете.
- `ext-apcu` для ограничения в пределах одного хоста (`ApcuBulkheadStore`) —
  опционально, не обязательная зависимость

## Установка

```bash
composer require rasuvaeff/bulkhead

# for RedisBulkheadStore with the pure-PHP client:
composer require predis/predis
```

## Использование

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

С `ext-redis` вместо predis:

```php
use Rasuvaeff\Bulkhead\Redis\PhpRedisScriptRunner;

$redis = new \Redis();
$redis->connect('127.0.0.1');
$store = new RedisBulkheadStore(new PhpRedisScriptRunner($redis));
```

Опциональные ручки:

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

### Публичный API

| Тип | Описание |
|---|---|
| `Bulkhead` | Интерфейс: `call(callable): mixed`, `availableSlots(): int` |
| `SharedBulkhead` | Ограничивает параллелизм через `BulkheadStore`; fast-fail или ожидание до `maxWait`; открывает `name()`, `maxConcurrent()` |
| `BulkheadStore` | Backing-хранилище: `tryAcquire`, `release`, `activeCount` |
| `RedisBulkheadStore` | Межхостовое cross-process-хранилище; sorted set + Lua, атомарный acquire, TTL lease |
| `ApcuBulkheadStore` | Однохостовое cross-process-хранилище; spinlock на APCu, атомарный acquire, TTL lease |
| `InMemoryBulkheadStore` | Однопроцессное хранилище (тесты/CLI); не координирует процессы |
| `BulkheadScriptRunner` | Типизированный шов поверх вызова Redis-скрипта (реализуйте для других клиентов) |
| `Redis\PredisScriptRunner` | `BulkheadScriptRunner` поверх predis; EVALSHA с откатом на EVAL |
| `Redis\PhpRedisScriptRunner` | `BulkheadScriptRunner` поверх `ext-redis`; EVALSHA с откатом на EVAL |
| `BulkheadFullException` | Выбрасывается, когда за `maxWait` нет свободного слота; несёт `name`, `maxConcurrent` |
| `Sleeper\SleeperInterface` | Стратегия ожидания при polling; `SystemSleeper`, `FakeSleeper` |

### Подбор параметров

- **`maxConcurrent`** — то, что выдерживает *downstream*, а не то, что пул может
  отдать. Если зависимость спокойно держит ~10 одновременных соединений, а у вас
  3 хоста приложения с общим Redis, то `maxConcurrent: 10` ограничивает их сумму.
  Значение должно быть меньше числа FPM-воркеров, иначе bulkhead никогда не
  сработает: при 50 воркерах и `maxConcurrent: 100` он не включится.
- **`lease`** — строго больше худшего времени работы callback'а; на практике:
  downstream-таймаут + запас. Слишком короткий — и слоты освобождаются прямо во
  время вызова (превышение лимита); слишком длинный — и слот упавшего воркера
  остаётся занятым всю аренду (недобор лимита). Для HTTP-вызова с таймаутом 5 с
  нормальный старт — `lease: Duration::seconds(10)`.
- **`maxWait`** — как долго запрос может стоять в очереди за слотом.
  `Duration::zero()` даёт fast-fail (немедленный сброс нагрузки); любое большее
  значение разменивает задержку на более низкий процент отказов. Держите его
  заметно меньше собственного таймаута запроса.
- **`pollJitter`** — ставьте `0.1`–`0.5`, когда одновременно могут ждать много
  воркеров: иначе освобождённый слот атакуется всеми ожидающими на одном 50 мс тике.

### Как лимит держится между воркерами

`RedisBulkheadStore` хранит sorted set для каждого bulkhead'а: каждый активный
слот — это member с оценкой (score) по времени истечения аренды. `tryAcquire`
выполняет один Lua-скрипт, который удаляет протухшие member'ы, проверяет
кардинальность против лимита и добавляет member — поэтому проверка и добавление
атомарны, и два воркера не могут одновременно проскочить за лимит. Воркер,
который упал во время вызова, ничего не утекает: score аренды его member'а
проходит, и слот возвращается при следующем acquire.

`ApcuBulkheadStore` хранит массив `token => expiresAt` для каждого bulkhead'а в
одной APCu-записи. В APCu нет серверных скриптов, поэтому атомарность
обеспечивается spinlock'ом: `tryAcquire`/`release` берут короткоживущий APCu-ключ
(`apcu_add` как create-if-absent) перед чтением или записью массива слотов, а
сама блокировка несёт TTL, так что воркер, умерший её держа, не блокирует
остальных намертво. Координирует только воркеры на **одном хосте** — общая память APCu не
распространяется на машины; для пула на несколько хостов используйте
`RedisBulkheadStore`.

## Безопасность

- `name` валидируется против `/^[A-Za-z0-9_.:-]+$/` и становится частью
  Redis/APCu-ключа — недоверенные имена отбрасываются, а не интерполируются вслепую.
- Значения попадают в Lua-скрипт как bound `ARGV`, без конкатенации строк.
- Пакет сам не открывает сетевых соединений — Redis-клиент поставляете вы.
- **Хранилище — жёсткая зависимость: недоступность даёт fail-closed, не
  fail-open.** Если настроенный Redis-клиент не может подключиться, или
  хранилище иначе падает с ошибкой, `call()` **не** допускает вызов молча —
  он бросает исключение. `call()` может бросить не только
  `BulkheadFullException`: обрыв соединения с Redis всплывает как
  `Predis\Connection\ConnectionException` (или эквивалент вашего клиента /
  `\RuntimeException` от `PhpRedisScriptRunner`), необработанный пакетом.
  Если вы ловите только `catch (BulkheadFullException)`, отказ самого
  хранилища пройдёт мимо этого блока.

## Подводные камни

- **`lease` должен превышать самое долгое ожидаемое время работы callback'а.**
  Если вызов идёт дольше аренды, хранилище освобождает слот посреди выполнения, и
  другой воркер может его забрать — параллелизм кратко превысит `maxConcurrent`.
  Размер аренды должен быть больше downstream-таймаута.
- `maxWait` — приблизительная граница на polling (по умолчанию гранулярность
  50 мс): время сетевого round-trip до хранилища на каждую попытку не учитывается,
  поэтому реальное wall time может слегка его превышать.
- **Ожидание не FIFO.** Ожидающие опрашивают; слот достаётся тому, кто опросил
  сразу после release. При устойчивой перегрузке ожидающий может голодать дольше
  `maxWait` и быть отброшенным, пока более поздние проходят.
- `availableSlots()` / `activeCount()` в Redis **пишут** (они удаляют протухшие
  member'ы), поэтому их нельзя наводить на read-only-реплику.
- `InMemoryBulkheadStore` работает только в рамках одного процесса — он **не**
  ограничивает пул FPM. Используйте его для тестов и CLI-инструментов.
- `ApcuBulkheadStore` ограничивает воркеров только в пределах **одной машины**.
  Пул на несколько хостов требует `RedisBulkheadStore`. Два острых угла
  APCu-spinlock'а:
  - `tryAcquire`/`release` крутятся до ~100 мс (настраивается через
    `lockMaxAttempts`/`lockRetryMicros`) на внутренней блокировке. Провалившийся
    `tryAcquire` сообщает «full»; провалившийся `release` оставляет слот
    истекать по аренде.
  - В APCu нет compare-and-delete, поэтому `unlock` не проверяет владение: держатель,
    застрявший дольше TTL блокировки (1 с) внутри микросекундного критического
    участка, может удалить блокировку преемника. Принято как незначительное для
    столь короткого участка; используйте Redis, если эта гарантия для вас важна.

## Примеры

См. [examples/](examples/) — запускаемые скрипты.

| Скрипт | Показывает | Нужен сервер? |
|---|---|---|
| `basic.php` | Хранилище в памяти, fast-fail при заполнении | нет |
| `redis.php` | Cross-process-ограничение через Redis | да (`REDIS_HOST`) |
| `apcu.php` | Однохостовое cross-process-ограничение через APCu | нет (нужен `ext-apcu`) |

## Разработка

На хосте нет PHP/Composer — запускайте через Docker-образ `composer:2`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```

Интеграционным тестам нужен Redis-сервер (автопропуск без `REDIS_HOST`),
`ext-apcu` (автопропуск через `ApcuBulkheadStore::isAvailable()`) и `ext-redis`
(автопропуск через `extension_loaded('redis')`); в базовом образе `composer:2` их
нет, поэтому гоняйте suite в образе с `apcu`, `pcntl` и `redis` (плюс
`apc.enable_cli=1`):

```bash
docker run -d --name bh-redis -p 6379:6379 redis:7-alpine
docker run --rm --network host -v "$PWD":/app -w /app -e REDIS_HOST=127.0.0.1 \
  <php-image-with-apcu-pcntl-redis> vendor/bin/testo --suite=Integration
docker rm -f bh-redis
```

## Лицензия

[BSD-3-Clause](LICENSE.md)
