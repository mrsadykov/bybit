# ⏰ Проверка настройки Cron

## Ваш текущий crontab:

```cron
* * * * * cd /var/www/trading-bot && php artisan schedule:run >> /dev/null 2>&1
*/5 * * * * /var/www/trading-bot/scripts/server-auto-pull.sh >/dev/null 2>&1
```

## ✅ Анализ:

### Строка 1: `schedule:run`
```cron
* * * * * cd /var/www/trading-bot && php artisan schedule:run >> /dev/null 2>&1
```

**Что это:**
- Laravel Scheduler - запускается каждую минуту
- Нужен для выполнения задач, определенных в `app/Console/Kernel.php` (если есть)

**Проблема:**
- Если `Kernel.php` не настроен с расписанием команд, эта строка ничего не делает
- В Laravel 12 может не быть `Kernel.php` (используется другой подход)

**Решение:**
- Если `Kernel.php` есть и настроен → оставить
- Если `Kernel.php` нет или не настроен → лучше использовать прямые команды

---

### Строка 2: `server-auto-pull.sh`
```cron
*/5 * * * * /var/www/trading-bot/scripts/server-auto-pull.sh >/dev/null 2>&1
```

**Что это:**
- Автоматическое обновление кода через Git (каждые 5 минут)
- ✅ Правильно настроено

---

## 🔧 Рекомендуемый crontab:

### Вариант 1: Прямые команды (рекомендуется)

```cron
# Автообновление кода (каждые 5 минут)
*/5 * * * * /var/www/trading-bot/scripts/server-auto-pull.sh >/dev/null 2>&1

# Запуск ботов (каждые 5 минут)
*/5 * * * * cd /var/www/trading-bot && php artisan bots:run >> /dev/null 2>&1

# Синхронизация ордеров (каждую минуту)
* * * * * cd /var/www/trading-bot && php artisan orders:sync >> /dev/null 2>&1

# Ежедневная статистика (в 9:00)
0 9 * * * cd /var/www/trading-bot && php artisan telegram:daily-stats >> /dev/null 2>&1
```

### Вариант 2: Через Laravel Scheduler (если Kernel.php настроен)

```cron
# Laravel Scheduler (каждую минуту)
* * * * * cd /var/www/trading-bot && php artisan schedule:run >> /dev/null 2>&1

# Автообновление кода (каждые 5 минут)
*/5 * * * * /var/www/trading-bot/scripts/server-auto-pull.sh >/dev/null 2>&1
```

И в `app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('bots:run')->everyFiveMinutes();
    $schedule->command('orders:sync')->everyMinute();
    $schedule->command('telegram:daily-stats')->dailyAt('09:00');
}
```

---

## ✅ Проверка текущего crontab:

```bash
crontab -l
```

## ✅ Проверка работы cron:

```bash
# Проверить логи cron (если есть)
tail -f /var/log/cron

# Или проверить работу команд вручную
cd /var/www/trading-bot && php artisan bots:run
cd /var/www/trading-bot && php artisan orders:sync
```

---

## ⚠️ Важно:

1. **`schedule:run`** запускается каждую минуту, но **не выполняет команды автоматически**, если они не настроены в `Kernel.php`

2. **Рекомендуется использовать прямые команды** в crontab, если `Kernel.php` не настроен

3. **Проверьте наличие `app/Console/Kernel.php`** и его настройку перед использованием `schedule:run`

---

## 🚀 Рекомендация:

**Используйте вариант 1 (прямые команды)** - это надежнее и проще для понимания:

```cron
*/5 * * * * /var/www/trading-bot/scripts/server-auto-pull.sh >/dev/null 2>&1
*/5 * * * * cd /var/www/trading-bot && php artisan bots:run >> /dev/null 2>&1
* * * * * cd /var/www/trading-bot && php artisan orders:sync >> /dev/null 2>&1
0 9 * * * cd /var/www/trading-bot && php artisan telegram:daily-stats >> /dev/null 2>&1
```
