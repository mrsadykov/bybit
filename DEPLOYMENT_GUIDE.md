# Руководство по деплою торгового бота

## 📋 О проекте

Торговый бот на Laravel 12 с поддержкой OKX и Bybit бирж. Автоматическая торговля на основе RSI + EMA стратегии.

## 🗄️ Выбор базы данных

**Рекомендация: MySQL**

Для торгового бота MySQL будет оптимальным выбором:
- ✅ Проще в настройке и управлении
- ✅ Быстрее для простых операций чтения/записи
- ✅ Меньше потребление ресурсов
- ✅ Широко используется в Laravel проектах
- ✅ Достаточно для ваших задач (торговые операции, логи)

PostgreSQL лучше для сложных аналитических запросов, но для вашего случая это избыточно.

## 🛠️ Инструмент для управления БД

**Рекомендация: Adminer**

**Преимущества Adminer:**
- ✅ Один PHP файл (легко установить и обновить)
- ✅ Безопаснее (можно ограничить доступ по IP)
- ✅ Поддерживает MySQL, PostgreSQL, SQLite
- ✅ Легкий и быстрый
- ✅ Не требует отдельной установки

**Установка Adminer:**
```bash
cd /var/www/trading-bot/public  # ВАЖНО: в public директорию!
wget https://www.adminer.org/latest.php -O adminer.php
chmod 644 adminer.php
chown www-data:www-data adminer.php
```

**Настройка безопасности в Nginx (для нестатического IP):**

**Вариант 1: Защита паролем через HTTP Basic Auth (рекомендуется)**
```bash
# Установка утилиты для создания паролей
sudo apt install apache2-utils

# Создание файла с паролем
sudo htpasswd -c /etc/nginx/.adminer-htpasswd admin
# Введите пароль при запросе
```

```nginx
# В секции server, внутри location / { ... }
location /adminer.php {
    auth_basic "Adminer Access";
    auth_basic_user_file /etc/nginx/.adminer-htpasswd;
    
    try_files $uri =404;
    fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
}
```

**Вариант 2: Ограничение по IP (если IP относительно стабильный)**
```nginx
location /adminer.php {
    allow YOUR_IP_ADDRESS;  # Ваш текущий IP
    deny all;
    try_files $uri =404;
    fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    fastcgi_index adminer.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

**Вариант 3: Комбинация - пароль + ограничение по IP (максимальная безопасность)**
```nginx
location /adminer.php {
    allow YOUR_IP_ADDRESS;
    deny all;
    
    auth_basic "Adminer Access";
    auth_basic_user_file /etc/nginx/.adminer-htpasswd;
    
    try_files $uri =404;
    fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    fastcgi_index adminer.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

**Альтернатива: phpMyAdmin** (если нужен более функциональный интерфейс)

---

## 🚀 Полное развертывание с нуля

### 1. Подготовка сервера

```bash
# Обновление системы (Ubuntu/Debian)
sudo apt update && sudo apt upgrade -y

# Установка необходимых пакетов
sudo apt install -y git curl unzip php8.2-fpm php8.2-cli php8.2-mysql php8.2-xml \
    php8.2-mbstring php8.2-curl php8.2-zip php8.2-bcmath php8.2-intl \
    nginx mysql-server composer

# Проверка версий
php -v
composer --version
mysql --version
nginx -v
```

### 2. Клонирование проекта с GitHub

```bash
# Переход в директорию для проектов
cd /var/www

# Клонирование репозитория
sudo git clone https://github.com/YOUR_USERNAME/YOUR_REPO.git trading-bot
cd trading-bot

# Установка прав
sudo chown -R www-data:www-data /var/www/trading-bot
sudo chmod -R 755 /var/www/trading-bot
```

### 3. Настройка базы данных MySQL

```bash
# Вход в MySQL
sudo mysql -u root -p

# Создание базы данных и пользователя
CREATE DATABASE trading_bot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'laravel'@'localhost' IDENTIFIED BY 'strong_password_here';
GRANT ALL PRIVILEGES ON trading_bot.* TO 'laravel'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 4. Настройка Laravel

```bash
cd /var/www/trading-bot

# Установка зависимостей
composer install --no-dev --optimize-autoloader
npm install && npm run build

# Копирование .env файла
cp .env.example .env

# Генерация ключа приложения
php artisan key:generate
```

### 5. Настройка .env файла

```bash
nano .env
```

```env
APP_NAME="Trading Bot"
APP_ENV=production
APP_KEY=base64:...  # сгенерируется автоматически
APP_DEBUG=false
APP_URL=https://your-domain.com

# База данных (MySQL)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=trading_bot
DB_USERNAME=laravel
DB_PASSWORD=strong_password_here

# Торговля
REAL_TRADING=false  # ВАЖНО: false для первого запуска!
OKX_API_PASSPHRASE=your_passphrase

# Telegram уведомления (опционально, но рекомендуется)
# Инструкции по настройке: см. TELEGRAM_SETUP.md
TELEGRAM_BOT_TOKEN=your_bot_token
TELEGRAM_CHAT_ID=your_chat_id

# Логирование
LOG_CHANNEL=daily
LOG_LEVEL=info

# Breeze (если используете)
SESSION_DRIVER=database
QUEUE_CONNECTION=database
```

### 6. Настройка Nginx

```bash
sudo nano /etc/nginx/sites-available/trading-bot
```

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/trading-bot/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
# Активация конфигурации
sudo ln -s /etc/nginx/sites-available/trading-bot /etc/nginx/sites-enabled/

# Проверка конфигурации
sudo nginx -t

# Перезагрузка Nginx
sudo systemctl reload nginx
```

### 7. Настройка прав доступа

```bash
cd /var/www/trading-bot

# ВАЖНО: .env должен быть защищен (только root может читать/писать)
sudo chmod 600 .env
sudo chown root:root .env

# Права для storage и bootstrap/cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# Права для vendor и node_modules (должны быть доступны для чтения)
sudo chown -R www-data:www-data vendor node_modules
sudo chmod -R 755 vendor node_modules

# Права для остальных файлов проекта
sudo chown -R www-data:www-data .
sudo find . -type f -exec chmod 644 {} \;
sudo find . -type d -exec chmod 755 {} \;

# Исключения: .env и artisan должны быть исполняемыми
sudo chmod 600 .env
sudo chmod 755 artisan
```

### 8. Выполнение миграций

```bash
php artisan migrate --force

# Если нужно заполнить начальные данные
php artisan db:seed
```

### 9. Создание администратора

**ВАЖНО:** Администратор нужен для создания аккаунта биржи и бота.

**Шаг 1:** Добавьте настройки администратора в `config/app.php` (в конец массива):

```php
'admin' => [
    'email' => env('ADMIN_EMAIL', 'admin@example.com'),
    'name' => env('ADMIN_NAME', 'Admin'),
    'password' => env('ADMIN_PASSWORD', 'change-me'),
],
```

**Шаг 2:** Добавьте в `.env`:

```env
ADMIN_EMAIL=admin@yourdomain.com
ADMIN_NAME=Admin
ADMIN_PASSWORD=strong_password_here
```

**Шаг 3:** Создание администратора:

```bash
php artisan create-admin
```

Команда создаст пользователя с указанными данными. Если пользователь уже существует, команда просто сообщит об этом.

### 11. Настройка Laravel Breeze (если используется)

```bash
# Уже установлен в проекте, но проверим
php artisan route:list | grep auth

# Если нужно переустановить:
# composer require laravel/breeze --dev
# php artisan breeze:install blade
# npm install && npm run build
```

### 12. Создание аккаунта биржи и бота

```bash
# Создание OKX аккаунта
php artisan create-okx-account

# Создание торгового бота
#php artisan create-okx-trading-bot
php artisan okx-bot:create BTCUSDT 5m rsi_ema 10
```

### 13. Настройка бота в базе данных

```bash
# Вход в MySQL
sudo mysql -u laravel -p trading_bot

# Проверка настроек бота
SELECT id, symbol, position_size, dry_run, is_active FROM trading_bots;

# ВАЖНО: Установить dry_run = 1 для первого запуска!
UPDATE trading_bots SET dry_run = 1 WHERE id = 1;

# Проверка
SELECT id, symbol, position_size, dry_run, is_active FROM trading_bots;
EXIT;
```

### 14. Восстановление существующих ордеров (опционально)

**Если на бирже уже есть ордера, которые нужно восстановить в БД:**

```bash
# Восстановление всех ордеров с биржи
php artisan trades:recover

# Или для конкретного символа
php artisan trades:recover --symbol=BTCUSDT

# Команда автоматически:
# - Получит все ордера с биржи
# - Создаст записи в БД
# - Свяжет SELL с BUY ордерами (parent_id)
# - Закроет позиции и рассчитает PnL
```

**Примечание:** Если это новый бот без истории ордеров, этот шаг можно пропустить.

### 15. Настройка автоматического запуска (Cron)

**Важно:** В Laravel 12 нет `app/Console/Kernel.php`. Используйте прямой cron или настройте через `routes/console.php`.

**Вариант 1: Прямой Cron (рекомендуется)**

```bash
crontab -e
```

Добавьте:
```bash
# Laravel Scheduler (запускается каждую минуту)
* * * * * cd /var/www/trading-bot && php artisan schedule:run >> /dev/null 2>&1

# Или напрямую команды:
# Запуск ботов каждые 5 минут
*/5 * * * * cd /var/www/trading-bot && php artisan bots:run >> /dev/null 2>&1

# Синхронизация статусов ордеров каждую минуту
* * * * * cd /var/www/trading-bot && php artisan orders:sync >> /dev/null 2>&1
```

**Вариант 2: Laravel Scheduler через routes/console.php**

Если хотите использовать Laravel Scheduler, добавьте в `routes/console.php`:

```php
<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Планировщик задач
Schedule::command('bots:run')->everyFiveMinutes();
Schedule::command('orders:sync')->everyMinute();
```

И в crontab только одну строку:
```bash
* * * * * cd /var/www/trading-bot && php artisan schedule:run >> /dev/null 2>&1
```

### 16. Первый запуск и тестирование

```bash
cd /var/www/trading-bot

# 1. Проверка подключения к бирже
#php artisan test-okx-connection
php artisan okx:test

# 2. Проверка баланса
php artisan check-balance

# 3. Запуск в dry_run режиме
php artisan bots:run
# Должно показать "DRY RUN BUY" или "DRY RUN SELL"

# 4. Проверка логов (если файл не существует, он создастся автоматически)
# Убедитесь, что директория существует и имеет правильные права
sudo mkdir -p storage/logs
sudo chown -R www-data:www-data storage/logs
sudo chmod -R 775 storage/logs
tail -f storage/logs/laravel.log

# 5. Проверка синхронизации
php artisan orders:sync
```

### 17. Настройка SSL (Let's Encrypt)

```bash
# Установка Certbot
sudo apt install certbot python3-certbot-nginx

# Получение сертификата
sudo certbot --nginx -d your-domain.com

# Автоматическое обновление
sudo certbot renew --dry-run
```

### 18. Включение реальной торговли

**⚠️ ВАЖНО:** Только после успешного тестирования в dry_run режиме!

```bash
# 1. Редактирование .env
nano .env
# Изменить: REAL_TRADING=true

# 2. Обновление бота в БД
sudo mysql -u laravel -p trading_bot
UPDATE trading_bots SET dry_run = 0 WHERE id = 1;
EXIT;

# 3. Запуск бота
php artisan bots:run
```

---

## 🔧 Управление и мониторинг

### Полезные команды

```bash
# Запуск ботов
php artisan bots:run

# Синхронизация статусов
php artisan orders:sync

# Восстановление ордеров
php artisan trades:recover

# Проверка баланса
php artisan check-balance

# Тест подключения
php artisan test-okx-connection

# Просмотр логов
tail -f storage/logs/laravel.log

# Очистка кэша
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### Мониторинг через Adminer

1. Откройте `http://your-domain.com/adminer.php`
2. Войдите с данными MySQL:
   - Система: MySQL
   - Сервер: localhost
   - Пользователь: laravel
   - Пароль: ваш пароль
   - База данных: trading_bot

### Проверка работы Cron

```bash
# Проверка логов cron
grep CRON /var/log/syslog | tail -20

# Проверка выполнения команд
cd /var/www/trading-bot
php artisan schedule:list  # Если используете Laravel Scheduler
```

---

## ⚠️ Важные моменты безопасности

1. **API ключи:**
   - Храните только в `.env` файле
   - Используйте только права Read и Trade
   - НЕ используйте права на вывод средств (Withdraw)

2. **База данных:**
   - Используйте сильные пароли
   - Ограничьте доступ к Adminer по IP
   - Регулярно делайте бэкапы

3. **Файлы:**
   - `.env` должен быть защищен (chmod 600)
   - `storage/` и `bootstrap/cache/` должны иметь правильные права

4. **Nginx:**
   - Настройте rate limiting
   - Используйте SSL
   - Ограничьте доступ к Adminer

---

## 📊 Стратегия торговли

Текущая стратегия: **RSI + EMA**
- **BUY:** RSI < 30 И цена > EMA(20)
- **SELL:** RSI > 70 И цена < EMA(20)
- **HOLD:** Во всех остальных случаях

Параметры можно изменить в `app/Trading/Strategies/RsiEmaStrategy.php`

---

## 🚨 Решение проблем

### Бот не торгует
- Проверьте `is_active = 1` в БД
- Проверьте `dry_run` режим
- Проверьте баланс на бирже
- Проверьте логи: `tail -f storage/logs/laravel.log`

### Ордера не синхронизируются
- Запустите `php artisan orders:sync` вручную
- Проверьте подключение к бирже
- Проверьте логи

### Ошибки API
- Проверьте API ключи в `.env`
- Проверьте права API ключей на бирже
- Проверьте лимиты API биржи

### Проблемы с правами
```bash
sudo chown -R www-data:www-data /var/www/trading-bot
sudo chmod -R 755 /var/www/trading-bot
sudo chmod -R 775 storage bootstrap/cache
```

---

## ✅ Чеклист перед запуском

- [ ] Сервер настроен (PHP, MySQL, Nginx, Composer)
- [ ] Проект склонирован с GitHub
- [ ] База данных создана и настроена
- [ ] `.env` файл настроен
- [ ] Миграции выполнены
- [ ] API ключи биржи настроены
- [ ] Торговый бот создан в БД
- [ ] `dry_run = 1` для первого запуска
- [ ] `REAL_TRADING=false` в .env
- [ ] Баланс на бирже достаточен
- [ ] Cron настроен
- [ ] Nginx настроен и работает
- [ ] SSL сертификат установлен (опционально)
- [ ] Логирование работает
- [ ] Adminer установлен и защищен

---

## 🎯 После успешного тестирования

1. Установить `REAL_TRADING=true` в `.env`
2. Установить `dry_run = 0` в базе данных
3. Запустить бота и проверить первую сделку
4. Мониторить работу в течение первых часов
5. Регулярно проверять логи и баланс
6. Настроить автоматические бэкапы БД

---

## 📝 Резервное копирование

### Автоматический бэкап БД

```bash
# Создание скрипта бэкапа
sudo nano /usr/local/bin/backup-trading-bot.sh
```

```bash
#!/bin/bash
BACKUP_DIR="/var/backups/trading-bot"
DATE=$(date +%Y%m%d_%H%M%S)
mkdir -p $BACKUP_DIR

# Бэкап БД
mysqldump -u laravel -p'your_password' trading_bot > $BACKUP_DIR/db_$DATE.sql

# Удаление старых бэкапов (старше 7 дней)
find $BACKUP_DIR -name "db_*.sql" -mtime +7 -delete
```

```bash
# Делаем исполняемым
sudo chmod +x /usr/local/bin/backup-trading-bot.sh

# Добавляем в cron (каждый день в 2:00)
0 2 * * * /usr/local/bin/backup-trading-bot.sh
```

---

## 📚 Дополнительные ресурсы

- [Laravel 12 Документация](https://laravel.com/docs/12.x)
- [Laravel Breeze](https://laravel.com/docs/breeze)
- [Adminer](https://www.adminer.org/)
- [Nginx Документация](https://nginx.org/en/docs/)
