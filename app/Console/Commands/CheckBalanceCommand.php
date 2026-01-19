<?php

namespace App\Console\Commands;

use App\Models\ExchangeAccount;
use App\Services\Exchanges\ExchangeServiceFactory;
use Illuminate\Console\Command;

class CheckBalanceCommand extends Command
{
    protected $signature = 'balance:check 
                            {coin=USDT : Coin to check (USDT, BTC, etc.)}
                            {--account= : Account ID to check (optional)}
                            {--testnet : Use testnet account}
                            {--production : Use production account}
                            {--exchange= : Exchange name (bybit, okx). If not specified, uses first available}';
    protected $description = 'Проверка баланса на бирже (Check balance on exchange)';

    public function handle(): int
    {
        $coin = strtoupper($this->argument('coin'));

        // Определяем какой аккаунт использовать
        $account = $this->getAccount();

        if (! $account) {
            $this->error('Exchange account not found.');
            $this->line('');
            $this->line('Available accounts:');
            $this->showAvailableAccounts();
            $this->line('');
            $this->line('Usage:');
            $this->line('  php artisan balance:check --account=2');
            $this->line('  php artisan balance:check --testnet');
            $this->line('  php artisan balance:check --production');
            return self::FAILURE;
        }

        $this->info('Проверка баланса... (Checking balance...)');
        $this->line('ID аккаунта (Account ID): ' . $account->id);
        $this->line('Биржа (Exchange): ' . $account->exchange);
        $this->line('Testnet: ' . ($account->is_testnet ? 'Да (Yes)' : 'Нет (No)'));
        $this->line('');

        try {
            $exchangeService = ExchangeServiceFactory::create($account);

            // Проверка конкретной монеты
            $balance = $exchangeService->getBalance($coin);
            $this->info("💰 {$coin} Баланс (Balance): {$balance}");

            $this->line('');
            $this->info('📊 Все балансы (All balances):');
            
            // Получаем балансы через метод, если он есть
            if (method_exists($exchangeService, 'getAllBalances')) {
                $allBalances = $exchangeService->getAllBalances();
                
                if (empty($allBalances)) {
                    $this->warn('Балансы не найдены (No balances found)');
                } else {
                    foreach ($allBalances as $coinName => $amount) {
                        if ($amount > 0) {
                            $this->line("  {$coinName}: {$amount}");
                        }
                    }
                }
            } else {
                // Для OKX получаем основные монеты (расширенный список)
                $mainCoins = ['BTC', 'USDT', 'ETH', 'SOL', 'BNB', 'ADA', 'DOGE', 'XRP'];
                foreach ($mainCoins as $mainCoin) {
                    try {
                        $coinBalance = $exchangeService->getBalance($mainCoin);
                        if ($coinBalance > 0) {
                            $this->line("  {$mainCoin}: {$coinBalance}");
                        }
                    } catch (\Throwable $e) {
                        // Игнорируем ошибки для монет, которых нет
                    }
                }
            }

            // Проверка цены для теста подключения
            $this->line('');
            $this->info('🔗 Тестирование подключения... (Testing connection...)');
            $price = $exchangeService->getPrice('BTCUSDT');
            $this->info("✅ Подключение OK (Connection OK). Цена BTC (BTC Price): {$price} USDT");

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            $this->error('Ошибка (Error): ' . $errorMessage);
            $this->line('');
            
            // Более детальные подсказки в зависимости от ошибки
            if (str_contains($errorMessage, 'encryption key')) {
                $this->warn('Ключ шифрования не установлен (Application encryption key not set).');
                $this->line('Выполните (Run): php artisan key:generate');
            } elseif (str_contains($errorMessage, 'payload is invalid') || str_contains($errorMessage, 'invalid signature')) {
                $this->warn('Возможные причины (Possible causes):');
                $this->line('1. Неверный API secret ключ (Wrong API secret key)');
                $this->line('2. API ключи не имеют права на чтение (API keys don\'t have Read permission)');
                $this->line('3. Ключи от production вместо testnet (или наоборот) (Keys from production instead of testnet)');
                $this->line('4. Проблема синхронизации времени (Time synchronization issue)');
            } elseif (str_contains($errorMessage, 'retCode') || str_contains($errorMessage, 'OKX API')) {
                $this->warn('API биржи вернул ошибку (Exchange API returned an error).');
                $this->line('Проверьте сообщение об ошибке выше (Check the error message above).');
            } else {
                $this->warn('Возможные причины (Possible causes):');
                $this->line('1. Неверные API ключи в .env (Wrong API keys in .env)');
                $this->line('2. API ключи не имеют права на чтение (API keys don\'t have Read permission)');
                $this->line('3. Ключи от production вместо testnet (Keys from production instead of testnet)');
                $this->line('4. Проблемы с сетью (Network issues)');
            }

            return self::FAILURE;
        }
    }

    /**
     * Получить аккаунт для проверки
     */
    private function getAccount(): ?ExchangeAccount
    {
        $exchangeFilter = $this->option('exchange');
        
        // Если указан конкретный ID
        if ($accountId = $this->option('account')) {
            $query = ExchangeAccount::where('id', $accountId);
            if ($exchangeFilter) {
                $query->where('exchange', $exchangeFilter);
            }
            return $query->first();
        }

        // Если указан testnet
        if ($this->option('testnet')) {
            $query = ExchangeAccount::where('is_testnet', true);
            if ($exchangeFilter) {
                $query->where('exchange', $exchangeFilter);
            }
            return $query->first();
        }

        // Если указан production
        if ($this->option('production')) {
            $query = ExchangeAccount::where('is_testnet', false);
            if ($exchangeFilter) {
                $query->where('exchange', $exchangeFilter);
            }
            return $query->first();
        }

        // По умолчанию - первый доступный аккаунт
        $query = ExchangeAccount::query();
        if ($exchangeFilter) {
            $query->where('exchange', $exchangeFilter);
        }
        
        // Сначала пробуем testnet
        $testnetAccount = (clone $query)->where('is_testnet', true)->first();
        if ($testnetAccount) {
            return $testnetAccount;
        }

        // Если testnet нет, берем production
        return $query->where('is_testnet', false)->first();
    }

    /**
     * Показать доступные аккаунты
     */
    private function showAvailableAccounts(): void
    {
        $accounts = ExchangeAccount::orderBy('exchange')
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            $this->line('  Аккаунты не найдены (No accounts found). Выполните (Run): php artisan setup');
            return;
        }

        $this->line('Доступные аккаунты (Available accounts):');
        foreach ($accounts as $account) {
            $type = $account->is_testnet ? 'Testnet' : 'Production';
            $this->line("  ID: {$account->id} - {$account->exchange} - {$type}");
        }
    }
}
