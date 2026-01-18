# 🔐 Исправление проблем с правами доступа

## Проблема: Permission denied при записи в лог-файлы

### Симптомы

При запуске команд Laravel (например, `php artisan bots:run`) появляется ошибка:

```
UnexpectedValueException
vendor/monolog/monolog/src/Monolog/Handler/StreamHandler.php:164
The stream or file "/var/www/trading-bot/storage/logs/laravel-2026-01-18.log" 
could not be opened in append mode: Failed to open stream: Permission denied
```

### Причина

Laravel пытается записать лог-файл, но веб-сервер (PHP-FPM, обычно работает от пользователя `www-data`) не имеет прав на запись в директорию `storage/logs/`.

### Решение

```bash
cd /var/www/trading-bot

# 1. Установка правильного владельца для storage
sudo chown -R www-data:www-data storage/

# 2. Установка прав на запись (775 = владелец и группа могут читать/писать/выполнять)
sudo chmod -R 775 storage/

# 3. То же самое для bootstrap/cache (если используется)
sudo chown -R www-data:www-data bootstrap/cache/
sudo chmod -R 775 bootstrap/cache/
```

### Альтернативное решение (если 775 не работает)

```bash
# Более широкие права (755 = владелец может все, остальные только чтение/выполнение)
sudo chmod -R 755 storage/
sudo chmod -R 755 bootstrap/cache/
```

### Проверка решения

```bash
# Проверка владельца и прав
ls -la storage/ | head -5

# Должно показать:
# drwxrwxr-x ... www-data www-data ... storage/
# drwxrwxr-x ... www-data www-data ... storage/logs/

# Попытка создать тестовый файл (должна пройти успешно)
sudo -u www-data touch storage/logs/test.log
sudo rm storage/logs/test.log

# Если команда выше выполнилась без ошибок, права настроены правильно
```

### Предотвращение проблемы при деплое

Добавьте эти команды в скрипт деплоя или выполняйте после каждого `git pull`:

```bash
#!/bin/bash
cd /var/www/trading-bot

# Создание директорий, если их нет
mkdir -p storage/logs
mkdir -p storage/framework/{sessions,views,cache}
mkdir -p bootstrap/cache

# Установка прав
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### Проверка пользователя PHP-FPM

Если проблема сохраняется, убедитесь, что PHP-FPM работает от пользователя `www-data`:

```bash
# Проверка пользователя PHP-FPM
ps aux | grep php-fpm | head -1

# Проверка конфигурации PHP-FPM
grep "user\|group" /etc/php/8.2/fpm/pool.d/www.conf

# Должно быть:
# user = www-data
# group = www-data

# Если другой пользователь, замените www-data на нужного в командах выше
```

### Дополнительные проверки

```bash
# Проверка существования директорий
ls -la storage/
ls -la storage/logs/
ls -la bootstrap/cache/

# Проверка прав на конкретный файл
ls -la storage/logs/laravel-*.log 2>/dev/null | head -1

# Если файл существует, проверьте его права
# Должно быть: -rw-rw-r-- ... www-data www-data
```

### Быстрое исправление (если проблема уже есть)

```bash
cd /var/www/trading-bot
sudo chown -R www-data:www-data storage/ bootstrap/cache/
sudo chmod -R 775 storage/ bootstrap/cache/
sudo chmod -R 755 storage/ bootstrap/cache/  # если 775 не работает
```

---

## 📚 См. также

- [DEPLOYMENT_GUIDE.md](./DEPLOYMENT_GUIDE.md) - Полное руководство по деплою
- [SERVER_SETUP.md](./SERVER_SETUP.md) - Настройка сервера
