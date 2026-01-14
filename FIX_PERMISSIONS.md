# Исправление прав доступа на сервере

## Проблемы

1. `.env` файл имеет неправильные права (755 вместо 600)
2. `adminer.php` находится не в `public/` директории
3. `node_modules` и `vendor` принадлежат root вместо www-data

## Решение

### 1. Исправление прав на .env

```bash
cd /var/www/trading-bot
sudo chmod 600 .env
sudo chown root:root .env
```

### 2. Перемещение adminer.php в public/

```bash
# Если adminer.php в корне проекта
cd /var/www/trading-bot
sudo mv adminer.php public/adminer.php
sudo chown www-data:www-data public/adminer.php
sudo chmod 644 public/adminer.php

# Или скачать заново в правильное место
cd /var/www/trading-bot/public
sudo wget https://www.adminer.org/latest.php -O adminer.php
sudo chown www-data:www-data adminer.php
sudo chmod 644 adminer.php
```

### 3. Исправление всех прав доступа

```bash
cd /var/www/trading-bot

# .env - только root может читать/писать
sudo chmod 600 .env
sudo chown root:root .env

# Storage и bootstrap/cache - должны быть доступны для записи
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# Vendor и node_modules - должны быть доступны для чтения
sudo chown -R www-data:www-data vendor node_modules
sudo chmod -R 755 vendor node_modules

# Остальные файлы проекта
sudo chown -R www-data:www-data .
sudo find . -type f -exec chmod 644 {} \;
sudo find . -type d -exec chmod 755 {} \;

# Исключения
sudo chmod 600 .env
sudo chmod 755 artisan
sudo chmod 755 public/adminer.php
```

### 4. Проверка прав

```bash
# Проверка .env
ls -l .env
# Должно быть: -rw------- root root

# Проверка adminer.php
ls -l public/adminer.php
# Должно быть: -rw-r--r-- www-data www-data

# Проверка storage
ls -ld storage
# Должно быть: drwxrwxr-x www-data www-data
```

### 5. Обновление конфигурации Nginx

Убедитесь, что в конфигурации Nginx указан правильный путь:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/trading-bot/public;  # ВАЖНО: public директория!

    # ... остальная конфигурация ...

    location /adminer.php {
        auth_basic "Adminer Access";
        auth_basic_user_file /etc/nginx/.adminer-htpasswd;
        
        try_files $uri =404;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

После изменений:
```bash
sudo nginx -t
sudo systemctl reload nginx
```

### 6. Проверка доступа к Adminer

Откройте в браузере:
```
http://your-domain.com/adminer.php
```

Должен появиться запрос на ввод пароля (если настроен HTTP Basic Auth).
