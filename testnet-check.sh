#!/bin/bash

# Скрипт для проверки подключения к Bybit Testnet

echo "🔍 Проверка подключения к Bybit Testnet..."
echo ""

# Проверка .env
if [ ! -f .env ]; then
    echo "❌ Файл .env не найден!"
    exit 1
fi

echo "✅ Файл .env найден"
echo ""

# Проверка конфигурации
echo "📋 Проверка конфигурации..."
php artisan tinker --execute="
\$account = \App\Models\ExchangeAccount::first();
if (!\$account) {
    echo '❌ ExchangeAccount не найден. Запустите: php artisan setup\n';
    exit(1);
}

echo '✅ ExchangeAccount найден\n';
echo '   Exchange: ' . \$account->exchange . '\n';
echo '   Testnet: ' . (\$account->is_testnet ? 'Да' : 'Нет') . '\n';
echo '';

// Проверка подключения
try {
    \$bybit = new \App\Services\Exchanges\Bybit\BybitService(\$account);
    
    echo '🔗 Проверка подключения к API...\n';
    \$price = \$bybit->getPrice('BTCUSDT');
    echo '✅ Цена получена: ' . \$price . ' USDT\n';
    echo '';
    
    echo '💰 Проверка баланса...\n';
    \$balance = \$bybit->getBalance('USDT');
    echo '✅ Баланс USDT: ' . \$balance . '\n';
    echo '';
    
    if (\$balance < 1) {
        echo '⚠️  ВНИМАНИЕ: Баланс меньше 1 USDT. Получите тестовые USDT на testnet.bybit.com\n';
    }
    
    echo '✅ Все проверки пройдены успешно!\n';
    
} catch (\Exception \$e) {
    echo '❌ Ошибка подключения: ' . \$e->getMessage() . '\n';
    echo '\n';
    echo 'Возможные причины:\n';
    echo '1. Неправильные API ключи в .env\n';
    echo '2. API ключи не имеют прав на Trade\n';
    echo '3. Ключи от production вместо testnet\n';
    echo '4. Проблемы с сетью\n';
    exit(1);
}
"

echo ""
echo "📊 Проверка ботов..."
php artisan tinker --execute="
\$bots = \App\Models\TradingBot::all();
echo 'Найдено ботов: ' . \$bots->count() . '\n';
\$active = \$bots->where('is_active', true);
echo 'Активных: ' . \$active->count() . '\n';
if (\$active->count() > 0) {
    echo '\nАктивные боты:\n';
    foreach (\$active as \$bot) {
        echo '  - Bot #' . \$bot->id . ': ' . \$bot->symbol . ' (' . \$bot->timeframe . ') - ' . \$bot->position_size . ' USDT\n';
    }
}
"

echo ""
echo "✅ Проверка завершена!"
