#!/bin/bash

# Git post-receive hook для автоматического деплоя
# Этот скрипт должен быть размещен на сервере в .git/hooks/post-receive
# Использование: git clone --bare ваш-репозиторий /var/repos/bybit.git

set -e

# Путь к рабочей директории проекта
DEPLOY_PATH="/var/www/bybit"
GIT_DIR="/var/repos/bybit.git"

echo "🚀 Автоматический деплой запущен (Auto-deploy triggered)..."

# Переход в рабочую директорию
cd "${DEPLOY_PATH}" || exit 1

# КРИТИЧНО: Установка прав доступа ДО любых операций Laravel
# Создание необходимых директорий с правильными правами
echo "🔐 Установка прав доступа для storage и bootstrap/cache (до операций Laravel)..."
sudo mkdir -p "${DEPLOY_PATH}/storage/logs" "${DEPLOY_PATH}/storage/framework/cache" "${DEPLOY_PATH}/storage/framework/sessions" "${DEPLOY_PATH}/storage/framework/views" "${DEPLOY_PATH}/bootstrap/cache" 2>/dev/null || true
sudo chown -R www-data:www-data "${DEPLOY_PATH}/storage" "${DEPLOY_PATH}/bootstrap/cache" 2>/dev/null || true
sudo chmod -R 775 "${DEPLOY_PATH}/storage" "${DEPLOY_PATH}/bootstrap/cache" 2>/dev/null || true

# Получение последних изменений
echo "📥 Получение изменений из Git..."
git --git-dir="${GIT_DIR}" --work-tree="${DEPLOY_PATH}" checkout -f main || \
git --git-dir="${GIT_DIR}" --work-tree="${DEPLOY_PATH}" checkout -f master

# Повторная установка прав доступа после checkout (на случай если права сбросились)
echo "🔐 Повторная установка прав доступа для storage и bootstrap/cache..."
sudo chown -R www-data:www-data "${DEPLOY_PATH}/storage" "${DEPLOY_PATH}/bootstrap/cache" 2>/dev/null || true
sudo chmod -R 775 "${DEPLOY_PATH}/storage" "${DEPLOY_PATH}/bootstrap/cache" 2>/dev/null || true

# Установка зависимостей Composer
echo "📦 Установка зависимостей Composer..."
composer install --no-dev --optimize-autoloader --quiet

# Установка зависимостей NPM
echo "📦 Установка зависимостей NPM..."
npm ci --production --silent

# Сборка фронтенда
echo "🔨 Сборка фронтенда..."
npm run build

# Запуск миграций
echo "🗄️  Запуск миграций..."
php artisan migrate --force --quiet

# Очистка кэша
echo "🧹 Очистка кэша..."
php artisan config:clear --quiet
php artisan cache:clear --quiet
php artisan view:clear --quiet
php artisan route:clear --quiet

# Оптимизация
echo "⚙️  Оптимизация..."
php artisan config:cache --quiet
php artisan route:cache --quiet
php artisan view:cache --quiet

echo ""
echo "✅ Деплой завершен успешно!"
echo "📋 Следующие шаги:"
echo "   - Проверьте логи: tail -f ${DEPLOY_PATH}/storage/logs/laravel.log"
echo "   - Запустите боты: php artisan bots:run"
