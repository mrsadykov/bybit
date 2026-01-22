# 🔧 Исправление проблем после деплоя

## Проблема 1: Permission denied для storage/logs

### Быстрое исправление на сервере:

```bash
# Подключитесь к серверу
ssh root@89.104.70.142

# Перейдите в директорию проекта
cd /var/www/trading-bot

# Установите правильные права доступа
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# Убедитесь, что директории существуют
sudo mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache

# Установите права для созданных директорий
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# Проверьте права
ls -la storage/logs/
```

### Если проблема сохраняется:

```bash
# Проверьте владельца веб-сервера
ps aux | grep -E '(apache|httpd|nginx|php-fpm)' | head -1

# Если используется другой пользователь (не www-data), замените www-data на нужного
# Например, для nginx может быть nginx, для apache - apache или www-data

# Для nginx:
sudo chown -R nginx:nginx storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# Для apache:
sudo chown -R apache:apache storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

## Проблема 2: Route [bots.index] not defined

### Исправление:

```bash
# На сервере
cd /var/www/trading-bot

# Очистите кеш маршрутов
php artisan route:clear

# Пересоздайте кеш маршрутов
php artisan route:cache

# Если проблема сохраняется, очистите весь кеш
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear

# Пересоздайте кеш
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Проверка маршрутов:

```bash
# Проверьте, что маршрут существует
php artisan route:list | grep bots.index

# Должен показать что-то вроде:
# GET|HEAD  bots ................ bots.index › BotController@index
```

## Полное исправление (все проблемы сразу):

```bash
# На сервере
ssh root@89.104.70.142
cd /var/www/trading-bot

# 1. Установка прав доступа
sudo mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# 2. Очистка и пересоздание кеша
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 3. Проверка
php artisan route:list | grep bots
ls -la storage/logs/
```

## Автоматическое исправление через скрипт

Создайте файл `fix-permissions.sh` на сервере:

```bash
#!/bin/bash
cd /var/www/trading-bot

echo "🔐 Установка прав доступа..."
sudo mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

echo "🧹 Очистка кеша..."
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear

echo "⚙️  Пересоздание кеша..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "✅ Готово!"
```

Затем запустите:
```bash
chmod +x fix-permissions.sh
./fix-permissions.sh
```
