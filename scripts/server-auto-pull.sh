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

# Переход в директорию проекта
if ! cd "${DEPLOY_PATH}" 2>/dev/null; then
    echo "[${DATE}] ❌ Ошибка: Не удалось перейти в директорию ${DEPLOY_PATH}" >&2
    exit 1
fi

# КРИТИЧНО: Установка прав доступа ДО любых операций Laravel
# Создание необходимых директорий с правильными правами
log "🔐 Установка прав доступа для storage и bootstrap/cache (до операций Laravel)..."
sudo mkdir -p "${DEPLOY_PATH}/storage/logs" "${DEPLOY_PATH}/storage/framework/cache" "${DEPLOY_PATH}/storage/framework/sessions" "${DEPLOY_PATH}/storage/framework/views" "${DEPLOY_PATH}/bootstrap/cache" 2>/dev/null || true
sudo chown -R www-data:www-data "${DEPLOY_PATH}/storage" "${DEPLOY_PATH}/bootstrap/cache" 2>/dev/null || true
sudo chmod -R 775 "${DEPLOY_PATH}/storage" "${DEPLOY_PATH}/bootstrap/cache" 2>/dev/null || true
# Убеждаемся, что файлы логов доступны для записи
sudo touch "${LOG_FILE}" 2>/dev/null || true
sudo chown www-data:www-data "${LOG_FILE}" 2>/dev/null || true
sudo chmod 664 "${LOG_FILE}" 2>/dev/null || true

log "🚀 Запуск проверки обновлений (Starting update check)..."

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

# Повторная установка прав доступа после git pull (на случай если права сбросились)
log "🔐 Повторная установка прав доступа для storage и bootstrap/cache..."
sudo chown -R www-data:www-data "${DEPLOY_PATH}/storage" "${DEPLOY_PATH}/bootstrap/cache" 2>/dev/null || true
sudo chmod -R 775 "${DEPLOY_PATH}/storage" "${DEPLOY_PATH}/bootstrap/cache" 2>/dev/null || true

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

# Очистка кэша (ВСЕГДА, чтобы избежать проблем с устаревшим кэшем)
log "🧹 Очистка кэша..."
run_cmd php artisan config:clear --quiet
run_cmd php artisan cache:clear --quiet
run_cmd php artisan view:clear --quiet
run_cmd php artisan route:clear --quiet

# Если изменились роуты или контроллеры - обязательно пересоздаем кэш роутов
if echo "$CHANGED_FILES" | grep -E -q '(routes/|app/Http/Controllers/)'; then
    log "🔄 Обнаружены изменения в роутах/контроллерах, пересоздаю кэш роутов..."
    run_cmd php artisan route:clear --quiet
    run_cmd php artisan route:cache --quiet
fi

# Оптимизация
log "⚙️  Оптимизация..."
run_cmd php artisan config:cache --quiet
# Кэш роутов создаем только если не создали выше
if ! echo "$CHANGED_FILES" | grep -E -q '(routes/|app/Http/Controllers/)'; then
    run_cmd php artisan route:cache --quiet
fi
run_cmd php artisan view:cache --quiet

log "✅ Деплой завершен успешно!"
log "──────────────────────────────────────"
