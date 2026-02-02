# Health Check (проверка здоровья системы)

Команда `php artisan health:check` проверяет доступность ключевых сервисов и факт недавнего успешного запуска ботов. При обнаружении сбоев отправляет алерт в Telegram.

## Что проверяется

1. **OKX Spot API** — запрос цены BTCUSDT через первый доступный OKX-аккаунт (спот).
2. **OKX Futures API** — запрос цены BTCUSDT через фьючерсный сервис, если есть активные фьючерсные боты с OKX.
3. **Telegram API** — запрос `getMe` к боту (health-токен или основной).
4. **Последний успешный запуск ботов** — в кэше должны быть ключи `health_last_bots_run`, `health_last_futures_run`, `health_last_btc_quote_run` с временной меткой не старше N минут (по умолчанию 15). Команды `bots:run`, `futures:run`, `btc-quote:run` в конце успешного выполнения записывают эти метки.

## Алерт в Telegram

При любой неудачной проверке команда завершается с кодом 1 и (если не передан `--no-alert`) отправляет в Telegram сообщение с перечислением сбоев. Используется health-чат (`TELEGRAM_HEALTH_CHAT_ID`), если задан, иначе основной чат. Чтобы не спамить, алерты ограничены кулдауном (по умолчанию 60 минут).

## Конфигурация

Файл `config/health.php`. Опционально в `.env`:

- `HEALTH_LAST_RUN_MAX_MINUTES` — максимальный возраст последнего запуска бота в минутах (по умолчанию 15).
- `HEALTH_ALERT_COOLDOWN_MINUTES` — кулдаун между алертами в минутах (по умолчанию 60).
- `HEALTH_CHECK_OKX_SPOT` — проверять OKX Spot (true/false).
- `HEALTH_CHECK_OKX_FUTURES` — проверять OKX Futures (true/false).
- `HEALTH_CHECK_TELEGRAM` — проверять Telegram API (true/false).

## Расписание

В `routes/console.php` команда запускается каждые 15 минут:

```php
Schedule::command('health:check')->everyFifteenMinutes();
```

## Ручной запуск

```bash
php artisan health:check
php artisan health:check --no-alert   # не отправлять алерт в Telegram при сбое
```
