#!/bin/bash

# Скрипт автоматического обновления через git pull
# Использование: Добавьте в cron или запустите через systemd timer
# Этот скрипт проверяет изменения в Git и обновляет проект

# Путь к проекту на сервере
DEPLOY_PATH="/var/www/trading-bot"

# Логирование
LOG_FILE="${DEPLOY_PATH}/storage/logs/auto-pull.log"
DATE=$(date '+%Y-%m-%d %H:%M:%S')

# Функция для логирования с обработкой ошибок
log() {
    echo "[${DATE}] $1" >> "${LOG_FILE}" 2>&1 || true
}

# Функция для выполнения команд с логированием ошибок
run_cmd() {
    if ! "$@" >> "${LOG_FILE}" 2>&1; then
        log "❌ Ошибка выполнения: $*"
        return 1
    fi
    return 0
}

# Проверка и создание директории логов
mkdir -p "$(dirname "${LOG_FILE}")" 2>/dev/null || true
touch "${LOG_FILE}" 2>/dev/null || true

log "🚀 Запуск проверки обновлений (Starting update check)..."

# Переход в директорию проекта
if ! cd "${DEPLOY_PATH}" 2>/dev/null; then
    log "❌ Ошибка: Не удалось перейти в директорию ${DEPLOY_PATH}"
    exit 1
fi

# Получение изменений без обновления
if ! run_cmd git fetch origin --quiet; then
    log "❌ Ошибка при получении изменений из Git"
    exit 1
fi

# Проверка, есть ли новые коммиты
LOCAL=$(git rev-parse HEAD 2>/dev/null || echo "")
REMOTE=$(git rev-parse origin/main 2>/dev/null || git rev-parse origin/main 2>/dev/null || echo "")

if [ -z "$LOCAL" ] || [ -z "$REMOTE" ]; then
    log "❌ Ошибка: Не удалось определить локальный или удаленный коммит"
    exit 1
fi

if [ "$LOCAL" = "$REMOTE" ]; then
    log "ℹ️  Нет новых изменений (No new changes)"
    exit 0
fi

log "🔄 Обнаружены новые изменения! Начинаю деплой (New changes detected! Starting deploy)..."
log "   Локальный коммит: ${LOCAL:0:8}"
log "   Удаленный коммит: ${REMOTE:0:8}"

# Получение изменений
if ! run_cmd git pull origin main || ! run_cmd git pull origin main; then
    log "❌ Ошибка при получении изменений (git pull failed)"
    exit 1
fi

# Установка прав доступа для storage и bootstrap/cache
log "🔐 Установка прав доступа для storage и bootstrap/cache..."
run_cmd sudo chown -R www-data:www-data "${DEPLOY_PATH}/storage" "${DEPLOY_PATH}/bootstrap/cache" || true
run_cmd sudo chmod -R 775 "${DEPLOY_PATH}/storage" "${DEPLOY_PATH}/bootstrap/cache" || true

# Сохраняем список измененных файлов
CHANGED_FILES=$(git diff --name-only HEAD@{1} HEAD 2>/dev/null || echo "")

# Установка зависимостей Composer (только если composer.lock изменился)
if echo "$CHANGED_FILES" | grep -q composer.lock; then
    log "📦 Установка зависимостей Composer..."
    run_cmd composer install --no-dev --optimize-autoloader --quiet
fi

# Установка зависимостей NPM (только если package-lock.json изменился)
if echo "$CHANGED_FILES" | grep -q package-lock.json; then
    log "📦 Установка зависимостей NPM..."
    run_cmd npm ci --production --silent
fi

# Сборка фронтенда (если изменились JS/CSS файлы)
if echo "$CHANGED_FILES" | grep -E -q '(\.js|\.ts|\.vue|\.css|\.scss|resources/)'; then
    log "🔨 Сборка фронтенда..."
    run_cmd npm run build
fi

# Запуск миграций (если изменились миграции)
if echo "$CHANGED_FILES" | grep -q 'database/migrations/'; then
    log "🗄️  Запуск миграций..."
    run_cmd php artisan migrate --force --quiet
fi

# Очистка кэша
log "🧹 Очистка кэша..."
run_cmd php artisan config:clear --quiet
run_cmd php artisan cache:clear --quiet
run_cmd php artisan view:clear --quiet
run_cmd php artisan route:clear --quiet

# Оптимизация
log "⚙️  Оптимизация..."
run_cmd php artisan config:cache --quiet
run_cmd php artisan route:cache --quiet
run_cmd php artisan view:cache --quiet

log "✅ Деплой завершен успешно!"
log "──────────────────────────────────────"
