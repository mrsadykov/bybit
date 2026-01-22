# 🔍 Диагностика Laravel Scheduler

## ❓ Почему не выполнился `positions:close-small`?

### Возможные причины:

1. **Cron не запускает `schedule:run`**
   - Laravel Scheduler требует, чтобы cron запускал `php artisan schedule:run` каждую минуту
   - Без этого scheduled команды НЕ выполняются

2. **Неправильное время (timezone)**
   - `dailyAt('17:26')` использует timezone приложения
   - Проверьте `APP_TIMEZONE` в `.env`

3. **Команда выполнилась, но была ошибка**
   - Проверьте логи: `storage/logs/laravel.log`

4. **`withoutOverlapping()` блокирует выполнение**
   - Если предыдущее выполнение еще не завершилось

---

## ✅ Проверка

### 1. Проверить, что cron запускает `schedule:run`

```bash
# На сервере проверить crontab
crontab -l

# Должна быть строка:
* * * * * cd /var/www/trading-bot && php artisan schedule:run >> /dev/null 2>&1
```

### 2. Проверить список scheduled команд

```bash
php artisan schedule:list
```

Должно показать:
```
26  17 * * *  php artisan positions:close-small
```

### 3. Проверить timezone

```bash
# В .env должно быть:
APP_TIMEZONE=UTC
# или
APP_TIMEZONE=Europe/Moscow
```

### 4. Проверить логи

```bash
# На сервере
tail -f storage/logs/laravel.log

# Или проверить логи scheduler
grep "positions:close-small" storage/logs/laravel.log
```

### 5. Запустить команду вручную

```bash
# Проверить, что команда работает
php artisan positions:close-small --dry-run

# Запустить реально
php artisan positions:close-small
```

### 6. Тест scheduler вручную

```bash
# Запустить scheduler вручную (выполнит все due команды)
php artisan schedule:run

# С verbose выводом
php artisan schedule:run -v
```

---

## 🔧 Решение

### Если cron не настроен:

```bash
# На сервере
crontab -e

# Добавить строку:
* * * * * cd /var/www/trading-bot && php artisan schedule:run >> /dev/null 2>&1
```

### Если проблема с timezone:

```bash
# В .env
APP_TIMEZONE=UTC

# Очистить кэш
php artisan config:clear
php artisan config:cache
```

### Если команда не выполняется из-за `withoutOverlapping()`:

```bash
# Проверить, не заблокирована ли команда
ls -la storage/framework/schedule-*

# Если файл существует и старый - удалить
rm storage/framework/schedule-*
```

---

## 📋 Чеклист

- [ ] Cron настроен: `crontab -l` показывает `schedule:run`
- [ ] Команда в списке: `php artisan schedule:list` показывает `positions:close-small`
- [ ] Timezone правильный: `APP_TIMEZONE` в `.env`
- [ ] Команда работает: `php artisan positions:close-small --dry-run` выполняется
- [ ] Логи проверены: нет ошибок в `storage/logs/laravel.log`
- [ ] Scheduler работает: `php artisan schedule:run -v` выполняет команды

---

## 🎯 Быстрая проверка на проде

```bash
# 1. Проверить cron
crontab -l

# 2. Проверить scheduled команды
php artisan schedule:list

# 3. Запустить scheduler вручную
php artisan schedule:run -v

# 4. Проверить логи
tail -20 storage/logs/laravel.log | grep -i "positions\|schedule"
```
