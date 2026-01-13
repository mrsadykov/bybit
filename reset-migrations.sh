#!/bin/bash

# Скрипт для отката и повторного применения миграций

echo "⚠️  ВНИМАНИЕ: Это удалит все данные из базы данных!"
read -p "Вы уверены? (yes/no): " confirm

if [ "$confirm" != "yes" ]; then
    echo "Отменено."
    exit 1
fi

echo "Откатываем все миграции..."
php artisan migrate:rollback --step=100

echo "Применяем миграции заново..."
php artisan migrate

echo "✅ Готово!"
