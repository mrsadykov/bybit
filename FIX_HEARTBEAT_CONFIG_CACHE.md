# Исправление: Laravel не видит TELEGRAM_HEALTH_CHAT_ID

## Проблема

На проде в `.env` есть `TELEGRAM_HEALTH_CHAT_ID=483474390`, но команда `php artisan telegram:heartbeat -v` показывает:
```
Health Chat ID: NOT SET
```

## Причина

Laravel кэширует конфигурацию для производительности. Если переменная была добавлена в `.env` после кэширования, Laravel её не увидит.

## Решение

### Шаг 1: Очистите кэш конфигурации

```bash
cd /var/www/trading-bot
php artisan config:clear
php artisan cache:clear
```

### Шаг 2: Проверьте, что переменная читается

```bash
php artisan config:show services.telegram
```

**Ожидаемый вывод:**
```php
array:5 [
  "bot_token" => "8507563866:..."
  "chat_id" => "483474390"
  "health_bot_token" => "8280381462:..."
  "health_chat_id" => "483474390"  // ← Должно быть видно!
]
```

### Шаг 3: Проверьте команду heartbeat

```bash
php artisan telegram:heartbeat -v
```

**Ожидаемый вывод:**
```
Checking Telegram health heartbeat configuration...
Health Chat ID: 483474390  // ← Теперь видно!
Health Bot Token: 8280381462...
Main Bot Token: 8507563866...
✅ Heartbeat sent successfully
```

---

## Если всё ещё не работает

### Проверка 1: Убедитесь, что переменная в `.env` без пробелов

```bash
grep TELEGRAM_HEALTH_CHAT_ID /var/www/trading-bot/.env
```

Должно быть:
```env
TELEGRAM_HEALTH_CHAT_ID=483474390
```

**НЕ должно быть:**
```env
TELEGRAM_HEALTH_CHAT_ID = 483474390  # ← пробелы вокруг =
TELEGRAM_HEALTH_CHAT_ID="483474390"  # ← кавычки (можно, но не обязательно)
```

### Проверка 2: Убедитесь, что нет дубликатов

```bash
grep -n TELEGRAM_HEALTH /var/www/trading-bot/.env
```

Должна быть только одна строка `TELEGRAM_HEALTH_CHAT_ID`.

### Проверка 3: Проверьте чтение напрямую из .env

```bash
php -r "echo getenv('TELEGRAM_HEALTH_CHAT_ID') ?: 'NOT SET';"
```

Если выводит `NOT SET` — проблема в `.env` (синтаксис, кодировка, права доступа).

---

## После исправления

После очистки кэша heartbeat должен работать. Проверьте:

```bash
php artisan telegram:heartbeat -v
```

Если видите `✅ Heartbeat sent successfully` — всё работает! Сообщение должно прийти в Telegram.

---

## Автоматическая очистка кэша (опционально)

Если часто добавляете переменные в `.env`, можно добавить в `routes/console.php`:

```php
// Очистка кэша конфига при изменении .env (только для development)
if (app()->environment('local')) {
    Schedule::command('config:clear')->hourly();
}
```

Но на production лучше очищать кэш вручную после изменений `.env`.
