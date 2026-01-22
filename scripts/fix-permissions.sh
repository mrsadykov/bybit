#!/bin/bash

# Скрипт для исправления прав доступа и кеша после деплоя
# Использование: ./fix-permissions.sh

set -e

# Путь к проекту на сервере
DEPLOY_PATH="/var/www/trading-bot"

echo "🔧 Исправление проблем после деплоя..."
echo ""

# Переход в директорию проекта
if ! cd "${DEPLOY_PATH}" 2>/dev/null; then
    echo "❌ Ошибка: Не удалось перейти в директорию ${DEPLOY_PATH}"
    echo "   Убедитесь, что путь правильный или запустите скрипт с сервера"
    exit 1
fi

# Определение пользователя веб-сервера
WEB_USER="www-data"
if command -v ps >/dev/null 2>&1; then
    # Попытка определить пользователя из процессов
    if ps aux | grep -q '[n]ginx'; then
        WEB_USER="nginx"
    elif ps aux | grep -q '[a]pache'; then
        WEB_USER="apache"
    fi
fi

echo "📁 Создание необходимых директорий..."
sudo mkdir -p "${DEPLOY_PATH}/storage/logs" \
    "${DEPLOY_PATH}/storage/framework/cache" \
    "${DEPLOY_PATH}/storage/framework/sessions" \
    "${DEPLOY_PATH}/storage/framework/views" \
    "${DEPLOY_PATH}/bootstrap/cache" 2>/dev/null || true

echo "🔐 Установка прав доступа (пользователь: ${WEB_USER})..."
sudo chown -R "${WEB_USER}:${WEB_USER}" "${DEPLOY_PATH}/storage" "${DEPLOY_PATH}/bootstrap/cache" 2>/dev/null || true
sudo chmod -R 775 "${DEPLOY_PATH}/storage" "${DEPLOY_PATH}/bootstrap/cache" 2>/dev/null || true

echo "🧹 Очистка кеша Laravel..."
php artisan config:clear --quiet || true
php artisan cache:clear --quiet || true
php artisan view:clear --quiet || true
php artisan route:clear --quiet || true

echo "⚙️  Пересоздание кеша..."
php artisan config:cache --quiet || true
php artisan route:cache --quiet || true
php artisan view:cache --quiet || true

echo ""
echo "✅ Готово!"
echo ""
echo "📋 Проверка:"
echo "   - Права доступа: ls -la ${DEPLOY_PATH}/storage/logs/"
echo "   - Маршруты: php artisan route:list | grep bots"
echo ""
