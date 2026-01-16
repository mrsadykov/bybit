#!/bin/bash

# Скрипт автоматического обновления через git pull
# Использование: Добавьте в cron или запустите через systemd timer
# Этот скрипт проверяет изменения в Git и обновляет проект

set -e

# Путь к проекту на сервере
DEPLOY_PATH="/var/www/bybit"

# Переход в директорию проекта
cd "${DEPLOY_PATH}" || exit 1

# Логирование
LOG_FILE="${DEPLOY_PATH}/storage/logs/auto-pull.log"
DATE=$(date '+%Y-%m-%d %H:%M:%S')

echo "[${DATE}] Проверка обновлений (Checking for updates)..." >> "${LOG_FILE}"

# Получение изменений без обновления
git fetch origin --quiet

# Проверка, есть ли новые коммиты
LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse origin/main 2>/dev/null || git rev-parse origin/master)

if [ "$LOCAL" = "$REMOTE" ]; then
    echo "[${DATE}] Нет новых изменений (No new changes)" >> "${LOG_FILE}"
    exit 0
fi

echo "[${DATE}] Обнаружены новые изменения! Начинаю деплой (New changes detected! Starting deploy)..." >> "${LOG_FILE}"

# Получение изменений
git pull origin main || git pull origin master

# Установка зависимостей Composer (только если composer.lock изменился)
if git diff --name-only HEAD@{1} HEAD | grep -q composer.lock; then
    echo "[${DATE}] Установка зависимостей Composer..." >> "${LOG_FILE}"
    composer install --no-dev --optimize-autoloader --quiet
fi

# Установка зависимостей NPM (только если package-lock.json изменился)
if git diff --name-only HEAD@{1} HEAD | grep -q package-lock.json; then
    echo "[${DATE}] Установка зависимостей NPM..." >> "${LOG_FILE}"
    npm ci --production --silent
fi

# Сборка фронтенда (если изменились JS/CSS файлы)
if git diff --name-only HEAD@{1} HEAD | grep -E -q '(\.js|\.ts|\.vue|\.css|\.scss|resources/)'; then
    echo "[${DATE}] Сборка фронтенда..." >> "${LOG_FILE}"
    npm run build
fi

# Запуск миграций (если изменились миграции)
if git diff --name-only HEAD@{1} HEAD | grep -q 'database/migrations/'; then
    echo "[${DATE}] Запуск миграций..." >> "${LOG_FILE}"
    php artisan migrate --force --quiet
fi

# Очистка кэша
echo "[${DATE}] Очистка кэша..." >> "${LOG_FILE}"
php artisan config:clear --quiet
php artisan cache:clear --quiet
php artisan view:clear --quiet
php artisan route:clear --quiet

# Оптимизация
echo "[${DATE}] Оптимизация..." >> "${LOG_FILE}"
php artisan config:cache --quiet
php artisan route:cache --quiet
php artisan view:cache --quiet

echo "[${DATE}] ✅ Деплой завершен успешно!" >> "${LOG_FILE}"
echo "[${DATE}] ──────────────────────────────────────" >> "${LOG_FILE}"
