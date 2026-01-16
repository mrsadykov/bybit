# 🔐 Настройка работы с Production сервером

## ⚠️ ВАЖНО: Безопасность

**Вы опубликовали пароль в открытом виде!**

1. **Смените пароль SSH немедленно**:
   ```bash
   ssh root@89.104.70.142
   passwd
   ```

2. **Настройте SSH ключи** (безопаснее паролей):
   ```bash
   # На вашем локальном компьютере
   ssh-copy-id root@89.104.70.142
   ```

3. **Отключите вход по паролю** (после настройки SSH ключей):
   ```bash
   # На сервере
   nano /etc/ssh/sshd_config
   # Измените: PasswordAuthentication no
   # Перезапустите: systemctl restart sshd
   ```

---

## 📋 Настройка проекта на сервере

### 1. Подключение к серверу

```bash
ssh root@89.104.70.142
```

### 2. Установка зависимостей (если еще не установлены)

```bash
# Composer
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer

# NPM (если не установлен)
apt-get update
apt-get install -y nodejs npm
```

### 3. Клонирование проекта (первый раз)

```bash
cd /var/www
git clone https://github.com/your-username/bybit.git  # Замените на ваш репозиторий
cd bybit

# Установка зависимостей
composer install --no-dev --optimize-autoloader
npm ci --production
npm run build

# Настройка .env
cp .env.example .env
nano .env  # Заполните все переменные

# Генерация ключа приложения
php artisan key:generate

# Запуск миграций
php artisan migrate --force

# Установка прав
chown -R www-data:www-data /var/www/bybit
chmod -R 755 /var/www/bybit
chmod -R 775 /var/www/bybit/storage
chmod -R 775 /var/www/bybit/bootstrap/cache
```

---

## 🚀 Автоматический деплой

### Вариант 1: Использование скрипта deploy.sh

```bash
# На локальном компьютере
./deploy.sh
```

### Вариант 2: Ручной деплой

```bash
# 1. Отправить изменения в Git
git push origin main

# 2. На сервере
ssh root@89.104.70.142
cd /var/www/bybit
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci --production
npm run build
php artisan migrate --force
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
```

### Вариант 3: Git Hooks (автоматический деплой при push)

```bash
# На сервере
cd /var/www/bybit
mkdir -p .git/hooks
nano .git/hooks/post-receive
```

Содержимое `post-receive`:
```bash
#!/bin/bash
cd /var/www/bybit
git --git-dir=.git --work-tree=/var/www/bybit checkout -f
composer install --no-dev --optimize-autoloader
npm ci --production
npm run build
php artisan migrate --force
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
```

```bash
chmod +x .git/hooks/post-receive
```

---

## 🔄 Рабочий процесс

### Локальная разработка:
1. Вносите изменения в код
2. Тестируете локально
3. Коммитите и пушите в Git: `git add . && git commit -m "описание" && git push`

### На сервере:
1. Автоматически (если настроены Git Hooks)
2. Или вручную: `./deploy.sh`
3. Или через SSH: `ssh root@89.104.70.142 "cd /var/www/bybit && git pull && ..."`

---

## 📝 Проверка после деплоя

```bash
# На сервере
cd /var/www/bybit

# Проверка логов
tail -f storage/logs/laravel.log

# Проверка статуса ботов
php artisan bots:run

# Синхронизация ордеров
php artisan orders:sync

# Тест Telegram
php artisan telegram:test
```

---

## 🔒 Рекомендации по безопасности

1. ✅ **Используйте SSH ключи вместо паролей**
2. ✅ **Отключите вход по паролю после настройки ключей**
3. ✅ **Не храните пароли в открытом виде**
4. ✅ **Используйте `.env` файл для секретов (не коммитьте его в Git!)**
5. ✅ **Регулярно обновляйте систему**: `apt-get update && apt-get upgrade`

---

## 🐛 Отладка проблем

Если что-то не работает:

1. **Проверьте логи**: `tail -f storage/logs/laravel.log`
2. **Проверьте права доступа**: `ls -la storage/`
3. **Проверьте .env**: `cat .env | grep -v PASSWORD`
4. **Очистите кэш**: `php artisan config:clear && php artisan cache:clear`
