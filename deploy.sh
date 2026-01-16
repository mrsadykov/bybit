#!/bin/bash

# Скрипт для автоматического деплоя на production сервер
# Использование: ./deploy.sh

set -e  # Остановить выполнение при ошибке

echo "🚀 Начало деплоя (Deploying to production)..."

# Параметры сервера (можно изменить или использовать переменные окружения)
SERVER_IP="${DEPLOY_SERVER_IP:-89.104.70.142}"
SERVER_USER="${DEPLOY_SERVER_USER:-root}"
SERVER_PATH="${DEPLOY_SERVER_PATH:-/var/www/bybit}"  # Путь к проекту на сервере

# Проверка, что мы в правильной директории
if [ ! -f "artisan" ]; then
    echo "❌ Ошибка: artisan файл не найден. Запустите скрипт из корня проекта."
    exit 1
fi

echo ""
echo "📦 Шаг 1: Проверка изменений в Git..."
git status

echo ""
read -p "Продолжить деплой? (y/n): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "❌ Деплой отменен"
    exit 1
fi

echo ""
echo "📤 Шаг 2: Отправка изменений в Git..."
git push origin main || git push origin master

echo ""
echo "🔐 Шаг 3: Подключение к серверу и обновление кода..."

# Подключение к серверу и выполнение команд
ssh ${SERVER_USER}@${SERVER_IP} bash -s "${SERVER_PATH}" << 'ENDSSH'
    set -e
    
    SERVER_PATH="$1"
    cd "${SERVER_PATH}" || exit 1
    
    echo "📥 Получение изменений из Git..."
    git pull origin main || git pull origin master
    
    echo "📦 Установка зависимостей Composer..."
    composer install --no-dev --optimize-autoloader
    
    echo "📦 Установка зависимостей NPM..."
    npm ci --production
    
    echo "🔨 Сборка фронтенда..."
    npm run build
    
    echo "🗄️  Запуск миграций..."
    php artisan migrate --force
    
    echo "🧹 Очистка кэша..."
    php artisan config:clear
    php artisan cache:clear
    php artisan view:clear
    php artisan route:clear
    
    echo "⚙️  Оптимизация..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    
    echo ""
    echo "✅ Деплой завершен!"
ENDSSH

echo ""
echo "✅ Деплой успешно завершен!"
echo ""
echo "📋 Следующие шаги на сервере:"
echo "1. Проверьте логи: tail -f ${SERVER_PATH}/storage/logs/laravel.log"
echo "2. Проверьте статус ботов: php artisan bots:run"
echo "3. Запустите синхронизацию: php artisan orders:sync"
