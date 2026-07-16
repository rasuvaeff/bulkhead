# Расуваефф/переборка
[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/bulkhead/v)](https://packagist.org/packages/rasuvaeff/bulkhead)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/bulkhead/downloads)](https://packagist.org/packages/rasuvaeff/bulkhead)
[![Build](https://github.com/rasuvaeff/bulkhead/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/bulkhead/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/bulkhead/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/bulkhead/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/bulkhead/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/bulkhead/php)](https://packagist.org/packages/rasuvaeff/bulkhead)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
Ограничитель межпроцессного параллелизма (переборка) для PHP-FPM. Ограничивает количество **одновременных** вызовов
 для хрупкой зависимости во **всем пуле рабочих**,
, поэтому всплеск не может нагружать каждого рабочего процесса в нисходящий поток, который допускает только несколько соединений
. Превышение лимита вызывает fast-fail (или кратковременное ожидание) вместо
, каскадно вызывающего сбой.

 Счетчик, общий в Redis или APCu, является точкой координации: в
 FPM без общего доступа ограничение должно находиться вне процесса, поскольку каждый запрос выполняется в
 со своим собственным исполнителем. Дополняет автоматический выключатель (который решает *стоит ли* попробовать
) — перегородка решает *сколько одновременно*.

 > Используете помощника по программированию с искусственным интеллектом? [llms.txt](llms.txt) содержит компактную ссылку на API, которой вы можете поделиться с моделью. @@ЛИНИЯ@@
## Требования
- PHP 8.3+
- [`rasuvaeff/duration`](https://github.com/rasuvaeff/duration) for the typed lease/wait values
- Для ограничения межпроцессного взаимодействия с несколькими хостами («RedisBulkheadStore»): доступный Redis
  server plus **one** Redis client — [`predis/predis`](https://github.com/predis/predis)
^2.2 (чистый PHP, PredisScriptRunner) или ext-redis (PhpRedisScriptRunner).
 Обе зависимости являются необязательными; установите тот, который используете.
 - `ext-apcu` для ограничения межпроцессного взаимодействия с одним хостом (`ApcuBulkheadStore`) — необязательно, а не жесткая зависимость

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
С ext-redis вместо predis:

```php
use Rasuvaeff\Bulkhead\Redis\PhpRedisScriptRunner;

$redis = new \Redis();
$redis->connect('127.0.0.1');
$store = new RedisBulkheadStore(new PhpRedisScriptRunner($redis));
```
Дополнительные ручки:

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
 | `Переборка` | Интерфейс: `call(callable): смешанный`, `availableSlots(): int` |
 | `Общая переборка` | Ограничивает параллелизм с помощью BulkheadStore; fast-fail или ждет до `maxWait`; предоставляет `name()`, `maxConcurrent()` |
 | `BulkheadStore` | Резервное хранилище: `tryAcquire`, `release`, `activeCount` |
 | `RedisBulkheadStore` | Многохостовое межпроцессное хранилище; отсортированный набор + Lua, атомарное приобретение, аренда TTL |
 | `ApcuBulkheadStore` | Межпроцессное хранилище с одним хостом; Спин-блокировка APCu, атомное приобретение, аренда TTL |
 | `InMemoryBulkheadStore` | Однопроцессное хранилище (тесты/CLI); не координирует процессы |
 | `BulkheadScriptRunner` | Напечатанный шов при вызове сценария Redis (реализовать для другого клиента) |
 | `Redis\PredisScriptRunner` | BulkheadScriptRunner с поддержкой Predis; EVALSHA с резервным вариантом EVAL |
 | `Redis\PhpRedisScriptRunner` | BulkheadScriptRunner с поддержкой `ext-redis`; EVALSHA с резервным вариантом EVAL |
 | `BulkheadFullException` | Вызывается, когда в течение `maxWait` нет свободного места; содержит `name`, `maxConcurrent` |
 | `Спящий\СпящийИнтерфейс` | Стратегия ожидания во время опроса; `SystemSleeper`, `FakeSleeper` | @@ЛИНИЯ@@
### Размер ручек
- **`maxConcurrent`** — то, что *нисходящий* поток* допускает, а не то, что пул может отправить
. Если зависимость легко обрабатывает около 10 одновременных подключений и вы
 запускаете 3 хоста приложений, использующих один Redis, `maxConcurrent: 10` ограничивает все хосты
 вместе. Чтобы что-то означать, оно должно быть меньше, чем количество рабочих FPM —
 с 50 рабочими и `maxConcurrent: 100`, переборка никогда не задействуется.
 - **`lease`** — строго больше, чем время выполнения обратного вызова в худшем случае, на практике
: тайм-аут нисходящего потока + запас прочности. Слишком коротко, и слоты
 освобождаются в середине вызова (превышение лимита); слишком долго, и слот
 для вышедшего из строя рабочего процесса остается занятым в течение всего срока аренды (ниже предела). Если обратный вызов представляет собой HTTP-вызов
 с таймаутом 5 секунд, то `lease: Duration::секунды(10)` будет разумным началом.
 - **`maxWait`** — как долго запрос может стоять в очереди в слот. `Duration::zero()`
 быстрый сбой (немедленное сброс нагрузки); все, что дольше, меняет задержку на более низкий процент отказов
. Держите это под своим тайм-аутом запроса.
 - **`pollJitter`** — установите значение `0,1`–`0,5`, когда много рабочих могут ждать одновременно,
, чтобы освободившийся слот не был забит каждым официантом в один и тот же тик 50 мс. @@ЛИНИЯ@@
### Как лимит распространяется на работников
RedisBulkheadStore хранит отсортированный набор для каждой переборки: каждый активный слот является членом
, оцениваемым по истечении срока аренды. `tryAcquire` запускает один Lua-скрипт, который
 удаляет истекшие члены, проверяет количество элементов на соответствие пределу и добавляет член
 — так что проверка и добавление являются атомарными, и два рабочих не могут одновременно пропустить
 за пределы лимита. Работник, который умирает во время разговора, ничего не теряет: оценка аренды
 его участника пройдена, и слот возвращается при следующем приобретении.

 `ApcuBulkheadStore` хранит массив `token => expiresAt` для каждой переборки в одной записи APCu
. В APCu нет сценариев на стороне сервера, поэтому атомарность вместо этого достигается за счет спин-блокировки
: `tryAcquire`/`release` берет недолговечный ключ APCu (`apcu_add` как
 create-if-absent) перед чтением или записью массива слотов, а сама блокировка
 содержит TTL, поэтому исполнитель, который умирает, удерживая его, не блокирует другие
. Координирует работу только на одном и том же хосте** — общая память APCu
 не распространяется на машины; используйте RedisBulkheadStore для распределения пула по хостам. @@ЛИНИЯ@@
## Безопасность
- `name` проверяется на соответствие `/^[A-Za-z0-9_.:-]+$/` и становится частью ключа
 Redis/APCu — ненадежные имена отклоняются, а не интерполируются вслепую.
 - Значения передаются в скрипт Lua как связанные `ARGV`, без объединения строк.
 - Пакет сам не открывает сетевые подключения; вы предоставляете клиент Redis. @@ЛИНИЯ@@
## Предостережения
- **`lease` должно превышать самое продолжительное ожидаемое время выполнения обратного вызова.** Если вызов
 длится дольше, чем его аренда, хранилище освобождает слот в середине выполнения, и другой исполнитель
 может его получить - тогда параллелизм на короткое время превышает `maxConcurrent`. Размер
 аренды превышает тайм-аут нисходящего потока.
 - `maxWait` — это приблизительная граница, основанная на опросе (детализация по умолчанию 50 мс):
 туда и обратно для каждой попытки не учитывается, поэтому реальное время стены может немного
 превышать его.
 - **Ожидание не FIFO.** Опрос официантов; тот, кто проголосует сразу после релиза
, выиграет слот. При постоянной перегрузке официант может не пройти мимо `maxWait`
 и получить отказ, пока не дойдут более поздние прибытия.
 — `availableSlots()` / `activeCount()` в Redis **write** (они удаляют истекшие члены
), поэтому их нельзя указать на реплику, доступную только для чтения.
 — `InMemoryBulkheadStore` предназначен только для одного процесса — он **не** ограничивает пул FPM
. Используйте его для тестов и инструментов CLI.
 - `ApcuBulkheadStore` ограничивает работников только на **одной машине**. Для пула
, распределенного по нескольким хостам, требуется RedisBulkheadStore. Два острых края спин-блокировки
 APCu:
 - вращение `tryAcquire`/`release` до ~100 мс (настраивается через
 `lockMaxAttempts`/`lockRetryMicros`) для внутренней блокировки. Неудачная попытка
 `tryAcquire` сообщает "полный"; неудачный запуск `release` оставляет слот до истечения срока аренды
.
 - APCu не имеет функции сравнения и удаления, поэтому `unlock` не может подтвердить право собственности: держатель
, остановившийся после TTL блокировки в 1 с внутри критической секции размером в микросекунду
, может удалить блокировку преемника. Принято как незначительное для такого маленького критического раздела
; используйте Redis, если эта гарантия имеет для вас значение. @@ЛИНИЯ@@
## Примеры
См. [examples/](examples/) для работоспособных сценариев.

 | Скрипт | Шоу | Нужен сервер? |
 |---|---|---|
 | `basic.php` | Хранилище в памяти, быстрое сбой при заполнении | нет |
 | `redis.php` | Ограничение межпроцессного взаимодействия с помощью Redis | да (`REDIS_HOST`) |
 | `apcu.php` | Ограничение межпроцессного взаимодействия с одним хостом с помощью APCu | нет (нужен ext-apcu) | @@ЛИНИЯ@@
## Разработка
На хосте нет PHP/Composer — запустите в Docker через образ `composer:2`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```
Для интеграционных тестов требуется сервер Redis (автопропуск, если не установлен `REDIS_HOST`),
 `ext-apcu` (самопропуск через `ApcuBulkheadStore::isAvailable()`) и `ext-redis`
 (автопропуск через `extension_loaded('redis')`); базовый образ `composer:2` не имеет
 ни одного из них, поэтому запустите пакет в образе, содержащем `apcu`, `pcntl` и `redis`
 (плюс `apc.enable_cli=1`):

```bash
docker run -d --name bh-redis -p 6379:6379 redis:7-alpine
docker run --rm --network host -v "$PWD":/app -w /app -e REDIS_HOST=127.0.0.1 \
  <php-image-with-apcu-pcntl-redis> vendor/bin/testo --suite=Integration
docker rm -f bh-redis
```
## Лицензия
[BSD-3-пункт](LICENSE.md)
